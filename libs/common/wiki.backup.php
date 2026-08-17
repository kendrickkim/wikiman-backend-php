<?php

const WIKI_BACKUP_EXTENSION = ".wkmbak";
const WIKI_BACKUP_MAGIC = "WIKIMNBK";
const WIKI_BACKUP_FORMAT_VERSION = 1;
const WIKI_BACKUP_SCHEMA_VERSION = 11;
const WIKI_BACKUP_MAX_UPLOAD_BYTES = 2147483648;
const WIKI_BACKUP_MAX_META_BYTES = 16777216;
const WIKI_BACKUP_MAX_INFLATED_BYTES = 8589934592;
const WIKI_BACKUP_COPY_CHUNK = 1048576;

class WIKI_BACKUP_ERROR extends RuntimeException
{
    public $status;

    public function __construct( $message, $status = 400 )
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }
}

function wiki_backup_fail( $message, $status = 400 )
{
    throw new WIKI_BACKUP_ERROR($message, $status);
}

function wiki_backup_required_schema()
{
    return [
        "users" => ["id", "username", "password_hash", "role"],
        "categories" => ["id", "name"],
        "posts" => ["id", "title", "slug", "author_id", "visibility", "status", "editor_type", "content"],
        "settings" => ["key", "value"],
        "post_keywords" => ["post_id", "keyword"],
        "post_attachments" => ["id", "post_id", "stored_name", "original_name", "mime_type", "size"],
        "quick_posts" => ["id", "author_id", "content"],
    ];
}

function wiki_backup_u32( $value )
{
    return pack("V", (int) $value);
}

function wiki_backup_read_u32( $bytes )
{
    $value = unpack("Vvalue", $bytes);
    return (int) $value["value"];
}

function wiki_backup_u64( $value )
{
    // P = unsigned 64-bit little endian.
    return pack("P", (int) $value);
}

function wiki_backup_read_u64( $bytes )
{
    $value = unpack("Pvalue", $bytes);
    return (int) $value["value"];
}

function wiki_backup_read_exact( $handle, $length )
{
    $result = "";
    while (strlen($result) < $length && !feof($handle)) {
        $chunk = fread($handle, $length - strlen($result));
        if ($chunk === false || $chunk === "") {
            break;
        }
        $result .= $chunk;
    }
    if (strlen($result) !== $length) {
        wiki_backup_fail("백업 파일이 중간에 끝났습니다.");
    }
    return $result;
}

function wiki_backup_gz_read_exact( $handle, $length )
{
    $result = "";
    while (strlen($result) < $length && !gzeof($handle)) {
        $chunk = gzread($handle, min(WIKI_BACKUP_COPY_CHUNK, $length - strlen($result)));
        if ($chunk === false || $chunk === "") {
            break;
        }
        $result .= $chunk;
    }
    if (strlen($result) !== $length) {
        wiki_backup_fail("백업 파일 항목이 중간에 끝났습니다.");
    }
    return $result;
}

function wiki_backup_schema_snapshot( PDO $db )
{
    $tables = [];
    foreach (wiki_backup_required_schema() as $table => $_columns) {
        $rows = $db->query("PRAGMA table_info(" . $table . ")")->fetchAll();
        $tables[$table] = array_map(function ($row) {
            return $row["name"];
        }, $rows);
    }
    return ["schemaVersion" => WIKI_BACKUP_SCHEMA_VERSION, "tables" => $tables];
}

function wiki_backup_validate_schema_snapshot( $schema )
{
    if (!is_array($schema)) {
        wiki_backup_fail("백업 메타데이터에 스키마 정보가 없습니다.");
    }
    $version = (int) ($schema["schemaVersion"] ?? 0);
    if ($version < 1) {
        wiki_backup_fail("지원하지 않는 스키마 버전입니다.");
    }
    if ($version > WIKI_BACKUP_SCHEMA_VERSION) {
        wiki_backup_fail(
            "이 백업은 스키마 버전 {$version}입니다. 현재 앱은 "
            . WIKI_BACKUP_SCHEMA_VERSION . "까지 지원합니다."
        );
    }

    $tables = $schema["tables"] ?? [];
    foreach (wiki_backup_required_schema() as $table => $columns) {
        $actual = $tables[$table] ?? null;
        if (!is_array($actual) || !$actual) {
            wiki_backup_fail("백업 DB에 필수 테이블이 없습니다: " . $table);
        }
        foreach ($columns as $column) {
            if (!in_array($column, $actual, true)) {
                wiki_backup_fail("백업 DB의 {$table} 테이블에 필수 컬럼이 없습니다: " . $column);
            }
        }
    }
}

function wiki_backup_validate_database( $path )
{
    try {
        $probe = new PDO("sqlite:" . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $probe->exec("PRAGMA query_only = ON");

        $names = $probe->query(
            "SELECT name FROM sqlite_master WHERE type IN ('table', 'view')"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach (wiki_backup_required_schema() as $table => $required) {
            if (!in_array($table, $names, true)) {
                wiki_backup_fail("복구 대상 DB에 필수 테이블이 없습니다: " . $table);
            }
            $columns = array_map(function ($row) {
                return $row["name"];
            }, $probe->query("PRAGMA table_info(" . $table . ")")->fetchAll());
            foreach ($required as $column) {
                if (!in_array($column, $columns, true)) {
                    wiki_backup_fail("복구 대상 DB의 {$table} 테이블에 필수 컬럼이 없습니다: " . $column);
                }
            }
        }

        if ((string) $probe->query("PRAGMA integrity_check")->fetchColumn() !== "ok") {
            wiki_backup_fail("복구 대상 DB 무결성 검사에 실패했습니다.");
        }
        if ($probe->query("PRAGMA foreign_key_check")->fetch()) {
            wiki_backup_fail("복구 대상 DB 외래 키 검사에 실패했습니다.");
        }
        $probe = null;
    } catch ( WIKI_BACKUP_ERROR $error ) {
        throw $error;
    } catch ( Throwable $error ) {
        wiki_backup_fail("백업 DB를 열 수 없습니다: " . $error->getMessage());
    }
}

function wiki_backup_file_list( $database_path = null )
{
    $files = [];
    $db_path = $database_path ?: wiki_config("database");
    if (!is_file($db_path)) {
        wiki_backup_fail("백업할 데이터베이스가 없습니다.", 500);
    }
    $files[] = ["path" => "wiki.db", "abs" => $db_path, "size" => (int) filesize($db_path)];

    foreach (wiki_walk_upload_files() as $file) {
        $files[] = [
            "path" => "uploads/" . $file["name"],
            "abs" => $file["path"],
            "size" => $file["size"],
        ];
    }
    usort($files, function ($a, $b) {
        if ($a["path"] === "wiki.db") return -1;
        if ($b["path"] === "wiki.db") return 1;
        return strcmp($a["path"], $b["path"]);
    });
    return $files;
}

/**
 * 열린 WAL DB를 파일 복사하지 않고 SQLite 자체 기능으로 일관된 단일 파일로 만든다.
 */
function wiki_backup_database_snapshot()
{
    $db = wiki_db();
    $db->exec("PRAGMA wal_checkpoint(FULL)");
    $path = wiki_config("data_dir") . "/.backup-db-" . bin2hex(random_bytes(8)) . ".db";
    $quoted = str_replace("'", "''", $path);
    try {
        $db->exec("VACUUM INTO '" . $quoted . "'");
        wiki_backup_validate_database($path);
        return $path;
    } catch ( Throwable $error ) {
        @unlink($path);
        if ($error instanceof WIKI_BACKUP_ERROR) throw $error;
        wiki_backup_fail("데이터베이스 스냅샷을 만들지 못했습니다.", 500);
    }
}

function wiki_backup_hash_range( $path, $offset, $length )
{
    $handle = fopen($path, "rb");
    if (!$handle || fseek($handle, $offset) !== 0) {
        wiki_backup_fail("백업 체크섬을 계산할 수 없습니다.", 500);
    }
    $hash = hash_init("sha256");
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($handle, min(WIKI_BACKUP_COPY_CHUNK, $remaining));
        if ($chunk === false || $chunk === "") {
            fclose($handle);
            wiki_backup_fail("백업 체크섬을 계산할 수 없습니다.", 500);
        }
        hash_update($hash, $chunk);
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    return hash_final($hash);
}

/**
 * Node 구현과 동일한 WIKIMNBK + JSON header + gzip entries + WKCK/SHA256 형식.
 */
function wiki_backup_create( $output_path )
{
    $db = wiki_db();
    $snapshot_path = wiki_backup_database_snapshot();
    try {
    $files = wiki_backup_file_list($snapshot_path);
    $created_at = new DateTimeImmutable("now", new DateTimeZone("UTC"));
    $meta = [
        "app" => "wikiman",
        "formatVersion" => WIKI_BACKUP_FORMAT_VERSION,
        "schemaVersion" => WIKI_BACKUP_SCHEMA_VERSION,
        "createdAt" => $created_at->format("Y-m-d\\TH:i:s.v\\Z"),
        "fileCount" => count($files),
        "totalBytes" => array_sum(array_column($files, "size")),
        "files" => array_map(function ($file) {
            return ["path" => $file["path"], "size" => $file["size"]];
        }, $files),
        "schema" => wiki_backup_schema_snapshot($db),
    ];

    $meta_json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($meta_json === false || strlen($meta_json) > WIKI_BACKUP_MAX_META_BYTES) {
        wiki_backup_fail("백업 메타데이터가 너무 큽니다.", 500);
    }

    $header = WIKI_BACKUP_MAGIC
        . wiki_backup_u32(WIKI_BACKUP_FORMAT_VERSION)
        . wiki_backup_u32(strlen($meta_json))
        . $meta_json;
    if (file_put_contents($output_path, $header, LOCK_EX) !== strlen($header)) {
        wiki_backup_fail("백업 파일을 만들 수 없습니다.", 500);
    }

    $gzip = gzopen($output_path, "ab6");
    if (!$gzip) {
        wiki_backup_fail("백업 압축 파일을 만들 수 없습니다.", 500);
    }
    try {
        foreach ($files as $file) {
            $name = $file["path"];
            if (strlen($name) > 65535) {
                wiki_backup_fail("경로가 너무 깁니다: " . $name, 500);
            }
            gzwrite($gzip, wiki_backup_u32(strlen($name)));
            gzwrite($gzip, $name);
            gzwrite($gzip, wiki_backup_u64($file["size"]));

            $input = fopen($file["abs"], "rb");
            if (!$input) {
                wiki_backup_fail("백업할 파일을 열 수 없습니다: " . $name, 500);
            }
            $remaining = $file["size"];
            while ($remaining > 0) {
                $chunk = fread($input, min(WIKI_BACKUP_COPY_CHUNK, $remaining));
                if ($chunk === false || $chunk === "") {
                    fclose($input);
                    wiki_backup_fail("파일 크기가 일치하지 않습니다: " . $name, 500);
                }
                gzwrite($gzip, $chunk);
                $remaining -= strlen($chunk);
            }
            fclose($input);
        }
    } finally {
        gzclose($gzip);
    }

    $payload_offset = strlen($header);
    $payload_length = filesize($output_path) - $payload_offset;
    $digest = wiki_backup_hash_range($output_path, $payload_offset, $payload_length);
    file_put_contents($output_path, "WKCK" . $digest, FILE_APPEND | LOCK_EX);

    $meta["payloadSha256"] = $digest;
    $meta["path"] = $output_path;
    return $meta;
    } finally {
        @unlink($snapshot_path);
    }
}

function wiki_backup_read_header( $path )
{
    $size = is_file($path) ? (int) filesize($path) : 0;
    if ($size < 16) {
        wiki_backup_fail("백업 파일이 너무 짧습니다.");
    }

    $handle = fopen($path, "rb");
    $prefix = wiki_backup_read_exact($handle, 16);
    if (substr($prefix, 0, 8) !== WIKI_BACKUP_MAGIC) {
        fclose($handle);
        wiki_backup_fail("Wikiman 백업 파일(.wkmbak)이 아닙니다.");
    }
    $format_version = wiki_backup_read_u32(substr($prefix, 8, 4));
    if ($format_version < 1) {
        fclose($handle);
        wiki_backup_fail("지원하지 않는 백업 형식 버전입니다.");
    }
    if ($format_version > WIKI_BACKUP_FORMAT_VERSION) {
        fclose($handle);
        wiki_backup_fail(
            "이 백업은 형식 버전 {$format_version}입니다. 현재 앱은 "
            . WIKI_BACKUP_FORMAT_VERSION . "까지 지원합니다."
        );
    }

    $meta_length = wiki_backup_read_u32(substr($prefix, 12, 4));
    if ($meta_length < 2 || $meta_length > WIKI_BACKUP_MAX_META_BYTES || $size < 16 + $meta_length) {
        fclose($handle);
        wiki_backup_fail("백업 헤더가 올바르지 않습니다.");
    }
    $meta_json = wiki_backup_read_exact($handle, $meta_length);
    $meta = json_decode($meta_json, true);
    if (!is_array($meta)) {
        fclose($handle);
        wiki_backup_fail("백업 헤더 JSON을 읽을 수 없습니다.");
    }
    if (($meta["app"] ?? null) !== "wikiman") {
        fclose($handle);
        wiki_backup_fail("Wikiman 백업 파일이 아닙니다.");
    }
    if ((int) ($meta["formatVersion"] ?? 0) !== $format_version) {
        fclose($handle);
        wiki_backup_fail("백업 헤더의 형식 버전이 일치하지 않습니다.");
    }
    wiki_backup_validate_schema_snapshot($meta["schema"] ?? null);

    $payload_offset = 16 + $meta_length;
    $payload_end = $size;
    $payload_sha = null;
    if ($size >= $payload_offset + 68) {
        fseek($handle, $size - 68);
        $footer = wiki_backup_read_exact($handle, 68);
        if (substr($footer, 0, 4) === "WKCK") {
            $candidate = substr($footer, 4);
            if (!preg_match('/^[a-f0-9]{64}$/', $candidate)) {
                fclose($handle);
                wiki_backup_fail("백업 체크섬이 올바르지 않습니다.");
            }
            $payload_sha = $candidate;
            $payload_end = $size - 68;
        }
    }
    fclose($handle);
    if ($payload_end <= $payload_offset) {
        wiki_backup_fail("백업 본문이 없습니다.");
    }

    return [
        "formatVersion" => $format_version,
        "meta" => $meta,
        "payloadOffset" => $payload_offset,
        "payloadEnd" => $payload_end,
        "payloadSha256" => $payload_sha,
        "size" => $size,
    ];
}

function wiki_backup_remove_tree( $path )
{
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== "." && $name !== "..") {
            wiki_backup_remove_tree($path . "/" . $name);
        }
    }
    @rmdir($path);
}

function wiki_backup_temp_dir( $prefix )
{
    $path = wiki_config("data_dir") . "/." . $prefix . "-" . bin2hex(random_bytes(8));
    if (!mkdir($path, 0750, true)) {
        wiki_backup_fail("임시 디렉터리를 만들 수 없습니다.", 500);
    }
    return $path;
}

function wiki_backup_copy_range( $source, $offset, $length, $destination )
{
    $input = fopen($source, "rb");
    $output = fopen($destination, "wb");
    if (!$input || !$output || fseek($input, $offset) !== 0) {
        if ($input) fclose($input);
        if ($output) fclose($output);
        wiki_backup_fail("백업 본문을 읽을 수 없습니다.");
    }
    $remaining = $length;
    while ($remaining > 0) {
        $chunk = fread($input, min(WIKI_BACKUP_COPY_CHUNK, $remaining));
        if ($chunk === false || $chunk === "") {
            fclose($input);
            fclose($output);
            wiki_backup_fail("백업 본문이 손상되었습니다.");
        }
        fwrite($output, $chunk);
        $remaining -= strlen($chunk);
    }
    fclose($input);
    fclose($output);
}

function wiki_backup_assert_path( $path )
{
    if ($path === "wiki.db") return;
    if (!str_starts_with($path, "uploads/") || basename($path) !== substr($path, 8)
        || strpos($path, "..") !== false || strpos($path, "\\") !== false) {
        wiki_backup_fail("허용되지 않는 백업 경로입니다: " . $path);
    }
}

/**
 * 압축 본문을 staging 디렉터리에 안전하게 푼다.
 */
function wiki_backup_unpack( $source, $destination )
{
    $header = wiki_backup_read_header($source);
    $payload_length = $header["payloadEnd"] - $header["payloadOffset"];
    $compressed = $destination . "/payload.gz";
    wiki_backup_copy_range($source, $header["payloadOffset"], $payload_length, $compressed);

    $digest = hash_file("sha256", $compressed);
    if ($header["payloadSha256"] && !hash_equals($header["payloadSha256"], $digest)) {
        wiki_backup_fail("백업 파일 체크섬이 일치하지 않습니다. 파일이 손상되었을 수 있습니다.");
    }

    $declared_total = (int) ($header["meta"]["totalBytes"] ?? 0);
    $inflated_limit = min(
        WIKI_BACKUP_MAX_INFLATED_BYTES,
        max(67108864, $declared_total + 67108864)
    );
    $expected = [];
    foreach (($header["meta"]["files"] ?? []) as $file) {
        if (!is_array($file) || !isset($file["path"], $file["size"])) {
            wiki_backup_fail("백업 메타데이터에 파일 목록이 없습니다.");
        }
        wiki_backup_assert_path((string) $file["path"]);
        if (isset($expected[$file["path"]])) {
            wiki_backup_fail("백업 메타데이터에 중복 경로가 있습니다: " . $file["path"]);
        }
        $expected[$file["path"]] = (int) $file["size"];
    }
    if (!$expected || !isset($expected["wiki.db"])) {
        wiki_backup_fail("백업에 wiki.db가 없습니다.");
    }

    mkdir($destination . "/uploads", 0750, true);
    $gzip = gzopen($compressed, "rb");
    if (!$gzip) {
        wiki_backup_fail("백업 본문을 압축 해제할 수 없습니다. 파일이 손상되었을 수 있습니다.");
    }

    $written = [];
    $inflated = 0;
    try {
        while (!gzeof($gzip)) {
            $length_bytes = gzread($gzip, 4);
            if ($length_bytes === "" && gzeof($gzip)) {
                break;
            }
            if ($length_bytes === false || strlen($length_bytes) !== 4) {
                wiki_backup_fail("백업 본문의 파일 항목이 손상되었습니다.");
            }
            $inflated += 4;
            $name_length = wiki_backup_read_u32($length_bytes);
            if ($name_length < 1 || $name_length > 65535) {
                wiki_backup_fail("백업 본문의 파일 경로가 올바르지 않습니다.");
            }
            $name = wiki_backup_gz_read_exact($gzip, $name_length);
            $size = wiki_backup_read_u64(wiki_backup_gz_read_exact($gzip, 8));
            $inflated += $name_length + 8 + $size;
            if ($inflated > $inflated_limit || $size < 0) {
                wiki_backup_fail("백업 압축 해제 크기가 한도를 초과했습니다.");
            }
            wiki_backup_assert_path($name);
            if (!isset($expected[$name]) || isset($written[$name])) {
                wiki_backup_fail("백업 본문에 허용되지 않은 파일이 있습니다: " . $name);
            }
            if ($expected[$name] !== $size) {
                wiki_backup_fail("백업 파일 크기가 메타데이터와 다릅니다: " . $name);
            }

            $output_path = $name === "wiki.db"
                ? $destination . "/wiki.db"
                : $destination . "/uploads/" . basename($name);
            $output = fopen($output_path, "wb");
            if (!$output) {
                wiki_backup_fail("백업 파일을 임시 저장할 수 없습니다.", 500);
            }
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = wiki_backup_gz_read_exact($gzip, min(WIKI_BACKUP_COPY_CHUNK, $remaining));
                fwrite($output, $chunk);
                $remaining -= strlen($chunk);
            }
            fclose($output);
            $written[$name] = true;
        }
    } finally {
        gzclose($gzip);
        @unlink($compressed);
    }

    foreach ($expected as $name => $_size) {
        if (!isset($written[$name])) {
            wiki_backup_fail("백업에 파일이 없습니다: " . $name);
        }
    }
    return ["header" => $header, "payloadSha256" => $digest];
}

function wiki_backup_result( $header, $payload_sha = null )
{
    $meta = $header["meta"];
    return [
        "ok" => true,
        "formatVersion" => (int) $header["formatVersion"],
        "schemaVersion" => (int) ($meta["schemaVersion"] ?? 0),
        "createdAt" => $meta["createdAt"] ?? null,
        "fileCount" => (int) ($meta["fileCount"] ?? 0),
        "totalBytes" => (int) ($meta["totalBytes"] ?? 0),
        "uploadCount" => max(0, (int) ($meta["fileCount"] ?? 1) - 1),
        "payloadSha256" => $payload_sha ?: ($header["payloadSha256"] ?? null),
    ];
}

function wiki_backup_inspect( $path )
{
    $temp = wiki_backup_temp_dir("backup-inspect");
    try {
        $unpacked = wiki_backup_unpack($path, $temp);
        wiki_backup_validate_database($temp . "/wiki.db");
        return wiki_backup_result($unpacked["header"], $unpacked["payloadSha256"]);
    } finally {
        wiki_backup_remove_tree($temp);
    }
}

function wiki_backup_copy_uploads( $source, $destination )
{
    if (!is_dir($destination)) mkdir($destination, 0750, true);
    if (!is_dir($source)) return;
    foreach (scandir($source) ?: [] as $name) {
        if ($name === "." || $name === ".." || str_starts_with($name, ".")) {
            continue;
        }
        $from = $source . "/" . $name;
        $to = $destination . "/" . $name;
        if (is_dir($from)) {
            if (!wiki_upload_is_month_folder($name)) {
                continue;
            }
            wiki_backup_copy_uploads($from, $to);
            continue;
        }
        if (!is_file($from)) {
            continue;
        }
        if (!copy($from, $to)) {
            wiki_backup_fail("첨부 파일을 복사할 수 없습니다: " . $name, 500);
        }
    }
}

function wiki_backup_empty_uploads( $path )
{
    if (!is_dir($path)) mkdir($path, 0750, true);
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== "." && $name !== ".." && !str_starts_with($name, ".")) {
            wiki_backup_remove_tree($path . "/" . $name);
        }
    }
}

/**
 * 검증된 staging 데이터를 교체하며, 실패하면 사전 복사본으로 되돌린다.
 */
function wiki_backup_restore( $path )
{
    $staging = wiki_backup_temp_dir("backup-restore");
    $safety_db = wiki_config("data_dir") . "/wiki.db.prerestore";
    $safety_uploads = wiki_config("data_dir") . "/uploads.prerestore";
    $swapped = false;

    try {
        $unpacked = wiki_backup_unpack($path, $staging);
        wiki_backup_validate_database($staging . "/wiki.db");

        wiki_db("close");
        @unlink(wiki_config("database") . "-wal");
        @unlink(wiki_config("database") . "-shm");

        if (is_file(wiki_config("database")) && !copy(wiki_config("database"), $safety_db)) {
            wiki_backup_fail("복구 전 데이터베이스를 복사하지 못했습니다.", 500);
        }
        wiki_backup_remove_tree($safety_uploads);
        mkdir($safety_uploads, 0750, true);
        wiki_backup_copy_uploads(wiki_config("uploads"), $safety_uploads);

        if (!copy($staging . "/wiki.db", wiki_config("database"))) {
            wiki_backup_fail("데이터베이스 파일을 교체할 수 없습니다.", 500);
        }
        $swapped = true;

        wiki_backup_empty_uploads(wiki_config("uploads"));
        wiki_backup_copy_uploads($staging . "/uploads", wiki_config("uploads"));
        @unlink($safety_db);
        wiki_backup_remove_tree($safety_uploads);

        // 새 DB를 열어 스키마/무결성을 한 번 더 확인한다.
        wiki_db();
        return wiki_backup_result($unpacked["header"]);
    } catch ( Throwable $error ) {
        wiki_db("close");
        if ($swapped && is_file($safety_db)) {
            @copy($safety_db, wiki_config("database"));
            wiki_backup_empty_uploads(wiki_config("uploads"));
            try {
                wiki_backup_copy_uploads($safety_uploads, wiki_config("uploads"));
            } catch ( Throwable $_ignored ) {
                // 원래 복구 오류를 유지한다.
            }
        }
        try {
            wiki_db();
        } catch ( Throwable $_ignored ) {
            // 호출부에 원래 오류를 전달한다.
        }
        throw $error;
    } finally {
        @unlink($safety_db);
        wiki_backup_remove_tree($safety_uploads);
        wiki_backup_remove_tree($staging);
    }
}

function wiki_backup_uploaded_file()
{
    $files = wiki_upload_entries("backup");
    if (!$files) {
        wiki_backup_fail("백업 파일이 필요합니다.");
    }
    $file = $files[0];
    $name = strtolower((string) ($file["name"] ?? ""));
    if (!str_ends_with($name, WIKI_BACKUP_EXTENSION)) {
        wiki_backup_fail("백업 파일은 " . WIKI_BACKUP_EXTENSION . " 확장자만 올릴 수 있습니다.");
    }
    $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        wiki_backup_fail(wiki_upload_error_message($error, 2048));
    }
    $size = (int) ($file["size"] ?? 0);
    if ($size <= 0 || $size > WIKI_BACKUP_MAX_UPLOAD_BYTES) {
        wiki_backup_fail("백업 파일은 2GB를 넘을 수 없습니다.");
    }
    $path = (string) ($file["tmp_name"] ?? "");
    if ($path === "" || (!is_uploaded_file($path) && PHP_SAPI !== "cli-server")) {
        wiki_backup_fail("올바른 백업 파일이 아닙니다.");
    }
    return $path;
}
