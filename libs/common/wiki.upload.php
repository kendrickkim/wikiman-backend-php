<?php

const WIKI_MAX_UPLOAD_FILES = 20;

const WIKI_IMAGE_MIME_TYPES = [
    "image/png" => "png",
    "image/jpeg" => "jpg",
    "image/gif" => "gif",
    "image/webp" => "webp",
    "image/svg+xml" => "svg",
];

function wiki_max_attachment_mb()
{
    $value = wiki_db()->query("SELECT `value` FROM settings WHERE `key` = 'max_attachment_mb'")->fetchColumn();
    return max(1, min(200, (int) ($value ?: 20)));
}

/**
 * PHASTAPI 의 REQUEST::GetFiles() 결과를 저장 함수가 쓰는 형태로 맞춘다.
 */
function wiki_upload_entries( $field )
{
    return array_map(function ($file) {
        return [
            "name" => (string) $file->name,
            "type" => (string) ($file->type ?? ""),
            "tmp_name" => (string) ($file->tmp_path ?? ""),
            "error" => (int) $file->error,
            "size" => (int) $file->size,
        ];
    }, REQUEST::GetFiles($field, false));
}

function wiki_upload_error_message( $error, $max_mb )
{
    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "파일 크기는 {$max_mb}MB를 넘을 수 없습니다.";
        case UPLOAD_ERR_PARTIAL:
            return "파일이 완전히 전송되지 않았습니다.";
        case UPLOAD_ERR_NO_FILE:
            return "올릴 파일이 필요합니다.";
        default:
            return "파일을 올릴 수 없습니다.";
    }
}

function wiki_safe_extension( $original_name, $mime )
{
    $ext = strtolower(pathinfo(basename($original_name), PATHINFO_EXTENSION));
    if ($ext !== "" && preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
        return $ext;
    }
    if (isset(WIKI_IMAGE_MIME_TYPES[$mime])) {
        return WIKI_IMAGE_MIME_TYPES[$mime];
    }
    return $mime === "image/x-icon" ? "ico" : "";
}

/**
 * 저장하기 전에 전송 오류와 크기를 확인한다.
 * 여러 파일을 받을 때는 먼저 전부 검사해야 중간 실패로 파일이 남지 않는다.
 */
function wiki_check_upload( $file, $max_bytes )
{
    $max_mb = (int) ceil($max_bytes / 1024 / 1024);

    $error = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        wiki_abort(400, $error === UPLOAD_ERR_NO_FILE ? "FILES_REQUIRED" : "UPLOAD_FAILED");
    }

    $size = (int) ($file["size"] ?? 0);
    if ($size <= 0 || $size > $max_bytes) {
        wiki_abort(400, "UPLOAD_TOO_LARGE", ["max" => $max_mb]);
    }
}

function wiki_upload_month_folder( $time = null )
{
    $ts = $time === null ? time() : (int) $time;
    return date("Y-m", $ts);
}

function wiki_upload_is_month_folder( $name )
{
    return (bool) preg_match('/^\d{4}-\d{2}$/', (string) $name);
}

/**
 * 업로드 파일을 data/uploads/YYYY-MM 로 옮기고 저장 정보를 돌려준다.
 * DB·URL의 storedName 은 basename 만 유지한다. MIME 은 파일 내용으로 판별한다.
 * 파비콘은 $flat=true 로 호출해 루트에 둔다.
 */
function wiki_store_upload( $file, $max_bytes, $allowed_mime = null, $type_message = null, $flat = false )
{
    wiki_check_upload($file, $max_bytes);

    $size = (int) $file["size"];
    $tmp = (string) ($file["tmp_name"] ?? "");
    if ($tmp === "" || (!is_uploaded_file($tmp) && PHP_SAPI !== "cli-server")) {
        wiki_abort(400, "UPLOAD_FAILED");
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($tmp) ?: "application/octet-stream");
    if ($allowed_mime !== null && !in_array($mime, $allowed_mime, true)) {
        wiki_abort(400, $type_message ?: "UPLOAD_TYPE_INVALID");
    }

    $original_name = mb_substr(basename((string) ($file["name"] ?? "file")), 0, 200);
    $ext = wiki_safe_extension($original_name, $mime);
    $stored_name = base_convert((string) (int) (microtime(true) * 1000), 10, 36)
        . "-" . bin2hex(random_bytes(6)) . ($ext !== "" ? "." . $ext : "");

    $uploads = wiki_config("uploads");
    if ($flat) {
        $target_dir = $uploads;
    } else {
        $target_dir = $uploads . "/" . wiki_upload_month_folder();
        if (!is_dir($target_dir) && !@mkdir($target_dir, 0750, true) && !is_dir($target_dir)) {
            wiki_abort(500, "UPLOAD_FAILED");
        }
    }

    $target = $target_dir . "/" . $stored_name;
    $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : rename($tmp, $target);
    if (!$moved) {
        wiki_abort(500, "UPLOAD_FAILED");
    }
    @chmod($target, 0640);

    return [
        "storedName" => $stored_name,
        "originalName" => $original_name !== "" ? $original_name : $stored_name,
        "mimeType" => $mime,
        "size" => $size,
        "url" => "/api/files/" . rawurlencode($stored_name),
    ];
}

/**
 * 경로 탈출을 막고 실제 파일일 때만 경로를 돌려준다.
 * flat 파일을 우선하고, 없으면 YYYY-MM 하위만 탐색한다.
 */
function wiki_upload_path( $name )
{
    $base = basename((string) $name);
    if ($base === "" || $base !== $name || strpos($base, "..") !== false) {
        return null;
    }

    $uploads = wiki_config("uploads");
    $flat = $uploads . "/" . $base;
    if (is_file($flat)) {
        return $flat;
    }

    foreach (scandir($uploads) ?: [] as $dir) {
        if ($dir === "." || $dir === ".." || !wiki_upload_is_month_folder($dir)) {
            continue;
        }
        $candidate = $uploads . "/" . $dir . "/" . $base;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function wiki_walk_upload_files()
{
    $uploads = wiki_config("uploads");
    $files = [];

    foreach (scandir($uploads) ?: [] as $name) {
        if ($name === "." || $name === ".." || str_starts_with($name, ".")) {
            continue;
        }
        $path = $uploads . "/" . $name;
        if (is_file($path)) {
            $files[] = ["name" => $name, "path" => $path, "size" => (int) filesize($path)];
            continue;
        }
        if (!is_dir($path) || !wiki_upload_is_month_folder($name)) {
            continue;
        }
        foreach (scandir($path) ?: [] as $child) {
            if ($child === "." || $child === ".." || str_starts_with($child, ".")) {
                continue;
            }
            $child_path = $path . "/" . $child;
            if (!is_file($child_path)) {
                continue;
            }
            $files[] = ["name" => $child, "path" => $child_path, "size" => (int) filesize($child_path)];
        }
    }

    usort($files, function ($a, $b) {
        return strcmp($a["name"], $b["name"]);
    });

    return $files;
}

function wiki_favicon_stored_name()
{
    $value = (string) (wiki_db()->query("SELECT `value` FROM settings WHERE `key` = 'favicon'")->fetchColumn() ?: "");
    return preg_match('#^/api/files/([^/?\#]+)$#', $value, $match) ? basename(rawurldecode($match[1])) : "";
}

function wiki_used_upload_names()
{
    $used = [];

    $favicon = wiki_favicon_stored_name();
    if ($favicon !== "") {
        $used[$favicon] = true;
    }

    foreach (["upload_refs", "post_attachments"] as $table) {
        $names = wiki_db()->query("SELECT DISTINCT stored_name FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $name) {
            $used[(string) $name] = true;
        }
    }

    return $used;
}

/**
 * 어떤 글에서도 참조하지 않는 업로드 파일 목록.
 */
function wiki_orphan_uploads()
{
    $used = wiki_used_upload_names();
    $files = [];

    foreach (wiki_walk_upload_files() as $file) {
        if (isset($used[$file["name"]])) {
            continue;
        }
        $files[] = ["name" => $file["name"], "size" => $file["size"]];
    }

    return $files;
}

function wiki_unlink_unused_uploads( $names )
{
    foreach (array_unique($names) as $name) {
        if (wiki_favicon_stored_name() === $name) {
            continue;
        }
        $stmt = wiki_db()->prepare(
            "SELECT 1 FROM upload_refs WHERE stored_name = ?
             UNION SELECT 1 FROM post_attachments WHERE stored_name = ? LIMIT 1"
        );
        $stmt->execute([$name, $name]);
        if (!$stmt->fetchColumn()) {
            $path = wiki_upload_path(basename($name));
            if ($path) {
                @unlink($path);
            }
        }
    }
}

/**
 * 작성자는 모든 파일을, 그 외에는 읽을 수 있는 글에 붙은 파일만 볼 수 있다.
 */
function wiki_can_access_upload( $name, $post_id = null )
{
    $user = wiki_current_user();

    if ($user) {
        $stmt = wiki_db()->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'writer'");
        $stmt->execute([(int) $user["id"]]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    if (wiki_favicon_stored_name() === $name) {
        return true;
    }

    if ($post_id !== null) {
        $stmt = wiki_db()->prepare("SELECT * FROM posts WHERE id = ?");
        $stmt->execute([(int) $post_id]);
        if (!wiki_can_read_post($stmt->fetch() ?: null, $user)) {
            return false;
        }
        $stmt = wiki_db()->prepare(
            "SELECT 1 FROM upload_refs WHERE post_id = ? AND stored_name = ?
             UNION SELECT 1 FROM post_attachments WHERE post_id = ? AND stored_name = ? LIMIT 1"
        );
        $stmt->execute([(int) $post_id, $name, (int) $post_id, $name]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = wiki_db()->prepare(
        "SELECT posts.* FROM posts JOIN upload_refs ON upload_refs.post_id = posts.id
         WHERE upload_refs.stored_name = ?"
    );
    $stmt->execute([$name]);
    foreach ($stmt->fetchAll() as $post) {
        if (wiki_can_read_post($post, $user)) {
            return true;
        }
    }

    // 아직 어떤 글에도 붙지 않은 방금 올린 파일은 열어 준다.
    $stmt = wiki_db()->prepare(
        "SELECT 1 FROM upload_refs WHERE stored_name = ?
         UNION SELECT 1 FROM post_attachments WHERE stored_name = ? LIMIT 1"
    );
    $stmt->execute([$name, $name]);
    return !$stmt->fetchColumn();
}

/**
 * 브라우저에서 실행될 수 있는 형식은 항상 첨부(다운로드)로 내려보낸다.
 */
function wiki_send_file( $path, $download_name = null, $mime = null )
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $dangerous = in_array($extension, ["html", "htm", "svg", "xhtml", "xml", "js", "mjs", "css"], true);

    $detected = $mime ?: ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: "application/octet-stream");
    if (strpos($detected, "html") !== false || strpos($detected, "svg") !== false
        || strpos($detected, "xml") !== false || strpos($detected, "javascript") !== false) {
        $dangerous = true;
    }

    $filename = $download_name ?: basename($path);

    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Content-Type: " . ($dangerous ? "application/octet-stream" : $detected));
    header("Content-Disposition: " . ($dangerous || $download_name ? "attachment" : "inline")
        . "; filename*=UTF-8''" . rawurlencode($filename));
    header("Content-Length: " . filesize($path));
    readfile($path);
    exit(0);
}
