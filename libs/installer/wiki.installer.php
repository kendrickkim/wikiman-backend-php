<?php

function wiki_installer_escape( $value )
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function wiki_installer_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        "httponly" => true,
        "secure" => !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off",
        "samesite" => "Lax",
    ]);
    @session_start();
}

function wiki_installer_token()
{
    wiki_installer_start_session();
    if (empty($_SESSION["wikiman_install_token"])) {
        $_SESSION["wikiman_install_token"] = bin2hex(random_bytes(24));
    }
    return $_SESSION["wikiman_install_token"];
}

function wiki_installer_is_healthy( &$reason = null )
{
    if (!wiki_is_installed()) {
        $reason = "설정 파일이 없습니다.";
        return false;
    }
    try {
        $config = wiki_config("db");
        if (wiki_db_driver() === "mysql") {
            if (!extension_loaded("pdo_mysql")) throw new RuntimeException("pdo_mysql 확장이 없습니다.");
            $dsn = "mysql:host=" . $config["host"] . ";port=" . $config["port"]
                . ";dbname=" . $config["database"] . ";charset=" . $config["charset"];
            $probe = new PDO($dsn, $config["username"], $config["password"], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ]);
        } else {
            if (!extension_loaded("pdo_sqlite")) throw new RuntimeException("pdo_sqlite 확장이 없습니다.");
            if (!is_file($config["path"])) throw new RuntimeException("SQLite 파일이 없습니다.");
            $probe = new PDO("sqlite:" . $config["path"], null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        }
        if ($probe->query("SELECT 1 FROM users WHERE role = 'writer' LIMIT 1")->fetchColumn() === false) {
            throw new RuntimeException("관리자 계정이 없습니다.");
        }
        $probe = null;
        return true;
    } catch ( Throwable $error ) {
        $reason = "데이터베이스 설정을 확인할 수 없습니다.";
        error_log("wikiman installer health: " . $error->getMessage());
        return false;
    }
}

function wiki_installer_write_config( $config )
{
    $data_dir = wiki_config("data_dir");
    if (!is_dir($data_dir) && !mkdir($data_dir, 0750, true) && !is_dir($data_dir)) {
        throw new RuntimeException("data 디렉터리를 만들 수 없습니다.");
    }
    $target = wiki_config_file($data_dir);
    $temp = $target . ".tmp-" . bin2hex(random_bytes(6));
    $php = "<?php\n\n// Wikiman 설치기가 생성한 파일입니다. 웹에서 직접 제공하지 마세요.\nreturn "
        . var_export($config, true) . ";\n";
    if (file_put_contents($temp, $php, LOCK_EX) !== strlen($php)) {
        @unlink($temp);
        throw new RuntimeException("설정 파일을 쓸 수 없습니다.");
    }
    @chmod($temp, 0640);
    if (!@rename($temp, $target)) {
        @unlink($temp);
        throw new RuntimeException("설정 파일을 저장할 수 없습니다.");
    }
    return $target;
}

function wiki_installer_test_mysql( $db )
{
    if (!extension_loaded("pdo_mysql")) {
        throw new RuntimeException("PHP pdo_mysql 확장이 필요합니다.");
    }
    $database = (string) $db["database"];
    if (!preg_match('/^[a-zA-Z0-9._:-]{1,255}$/', (string) $db["host"])) {
        throw new RuntimeException("MySQL 호스트가 올바르지 않습니다.");
    }
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $database)) {
        throw new RuntimeException("MySQL 데이터베이스 이름은 영문, 숫자, 밑줄만 사용할 수 있습니다.");
    }
    $base = "mysql:host=" . $db["host"] . ";port=" . $db["port"] . ";charset=" . $db["charset"];
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        return new PDO($base . ";dbname=" . $database, $db["username"], $db["password"], $options);
    } catch ( PDOException $first ) {
        try {
            $server = new PDO($base, $db["username"], $db["password"], $options);
            $server->exec("CREATE DATABASE IF NOT EXISTS `" . $database
                . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            return new PDO($base . ";dbname=" . $database, $db["username"], $db["password"], $options);
        } catch ( Throwable $second ) {
            throw new RuntimeException("MySQL에 연결하거나 데이터베이스를 만들 수 없습니다.");
        }
    }
}

function wiki_installer_submit( $input )
{
    $repair_mode = wiki_is_installed() || is_file(wiki_config_file());
    $healthy_reason = null;
    if (wiki_installer_is_healthy($healthy_reason)) {
        throw new RuntimeException("이미 설치되어 정상 동작 중입니다. 재설치하려면 관리자 계정과 data/config.php를 직접 정리하세요.");
    }
    wiki_installer_start_session();
    $token = (string) ($input["token"] ?? "");
    if (!isset($_SESSION["wikiman_install_token"])
        || !hash_equals((string) $_SESSION["wikiman_install_token"], $token)) {
        throw new RuntimeException("설치 요청이 만료되었습니다. 페이지를 새로고침하세요.");
    }

    $required_install_token = trim((string) getenv("WIKIMAN_INSTALL_TOKEN"));
    if ($repair_mode && $required_install_token === "") {
        throw new RuntimeException(
            "기존 설치 복구에는 서버의 WIKIMAN_INSTALL_TOKEN 설정이 필요합니다."
        );
    }
    if ($required_install_token !== "") {
        $provided = (string) ($input["install_token"] ?? "");
        if (!hash_equals($required_install_token, $provided)) {
            throw new RuntimeException("설치 토큰이 올바르지 않습니다.");
        }
    }
    if (!extension_loaded("gd")) {
        throw new RuntimeException("이미지 썸네일 생성을 위해 PHP gd 확장이 필요합니다.");
    }

    $site_title = trim((string) ($input["site_title"] ?? ""));
    $username = trim((string) ($input["username"] ?? ""));
    $password = (string) ($input["password"] ?? "");
    $password_confirm = (string) ($input["password_confirm"] ?? "");
    $driver = ($input["db_driver"] ?? "sqlite") === "mysql" ? "mysql" : "sqlite";

    if ($site_title === "" || mb_strlen($site_title) > 80) {
        throw new RuntimeException("사이트 제목은 1~80자로 입력하세요.");
    }
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        throw new RuntimeException("관리자 아이디는 3~32자의 영문, 숫자, 밑줄만 사용할 수 있습니다.");
    }
    if (strlen($password) < 8) {
        throw new RuntimeException("관리자 비밀번호는 8자 이상이어야 합니다.");
    }
    if (!hash_equals($password, $password_confirm)) {
        throw new RuntimeException("관리자 비밀번호 확인이 일치하지 않습니다.");
    }

    $data_dir = wiki_config("data_dir");
    if (!is_dir($data_dir) && !mkdir($data_dir, 0750, true) && !is_dir($data_dir)) {
        throw new RuntimeException("data 디렉터리를 만들 수 없습니다.");
    }
    $lock_path = $data_dir . "/install.lock";
    $lock = fopen($lock_path, "c+");
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        throw new RuntimeException("다른 설치가 진행 중입니다. 잠시 후 다시 시도하세요.");
    }

    try {
        if (wiki_installer_is_healthy($healthy_reason)) {
            throw new RuntimeException("이미 설치되어 정상 동작 중입니다.");
        }

        $db = [
            "driver" => $driver,
            "path" => $data_dir . "/wiki.db",
            "host" => trim((string) ($input["mysql_host"] ?? "127.0.0.1")),
            "port" => max(1, min(65535, (int) ($input["mysql_port"] ?? 3306))),
            "database" => trim((string) ($input["mysql_database"] ?? "")),
            "username" => trim((string) ($input["mysql_username"] ?? "")),
            "password" => (string) ($input["mysql_password"] ?? ""),
            "charset" => "utf8mb4",
        ];

        if ($driver === "sqlite") {
            if (!extension_loaded("pdo_sqlite")) {
                throw new RuntimeException("PHP pdo_sqlite 확장이 필요합니다.");
            }
            if (!is_writable($data_dir)) {
                throw new RuntimeException("data 디렉터리에 쓰기 권한이 없습니다.");
            }
        } else {
            wiki_installer_test_mysql($db);
        }

        $config_file = null;
        $marker_file = null;
        $previous_config = is_file(wiki_config_file($data_dir))
            ? file_get_contents(wiki_config_file($data_dir))
            : null;
        $sqlite_existed = is_file($db["path"]);
        try {
            $config_file = wiki_installer_write_config([
                "installed" => true,
                "installed_at" => gmdate("c"),
                "site_title" => $site_title,
                "db" => $db,
            ]);
            wiki_reload_config();
            wiki_db("close");
            $pdo = wiki_db();
            $has_writer = $pdo->query("SELECT 1 FROM users WHERE role = 'writer' LIMIT 1")->fetchColumn() !== false;
            $pdo->beginTransaction();
            if (!$has_writer) {
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'writer')");
                $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT, ["cost" => 10])]);
            }
            $sql = wiki_db_driver() === "mysql"
                ? "INSERT INTO settings (`key`, `value`) VALUES ('site_title', ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
                : "INSERT INTO settings (`key`, `value`) VALUES ('site_title', ?)
                   ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$site_title]);
            $pdo->commit();
            if ($has_writer) {
                error_log("wikiman: installer repaired config; existing writer kept");
            }
            $public_dir = rtrim((string) (getenv("WIKIMAN_FRONTEND_DIR")
                ?: dirname(__DIR__, 2) . "/public"), "/");
            if (is_dir($public_dir)) {
                $marker_file = $public_dir . "/.wikiman-installed";
                @file_put_contents($marker_file, gmdate("c") . "\n", LOCK_EX);
                @chmod($marker_file, 0640);
            }
            unset($_SESSION["wikiman_install_token"]);
            return true;
        } catch ( Throwable $error ) {
            try {
                $pdo = isset($pdo) ? $pdo : null;
                if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            } catch ( Throwable $_ignored ) {
            }
            wiki_db("close");
            if ($previous_config !== null) {
                @file_put_contents(wiki_config_file($data_dir), $previous_config, LOCK_EX);
            } else if ($config_file) {
                @unlink($config_file);
            }
            if ($marker_file) @unlink($marker_file);
            if ($driver === "sqlite" && !$sqlite_existed) {
                @unlink($db["path"]);
                @unlink($db["path"] . "-wal");
                @unlink($db["path"] . "-shm");
            }
            wiki_reload_config();
            throw $error;
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function wiki_installer_render( $error = "", $status = 200 )
{
    http_response_code($status);
    header("Content-Type: text/html; charset=utf-8");
    header("Cache-Control: no-store");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    $input = $_POST;
    $driver = ($input["db_driver"] ?? "sqlite") === "mysql" ? "mysql" : "sqlite";
    $token = wiki_installer_token();
    $e = "wiki_installer_escape";
    ?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Wikiman 설치</title>
  <style>
    :root{color-scheme:light dark;font-family:system-ui,sans-serif}body{margin:0;background:#f3f5f7;color:#17202a}
    main{max-width:720px;margin:40px auto;padding:28px;background:#fff;border-radius:14px;box-shadow:0 8px 30px #0002}
    h1{margin-top:0}fieldset{border:1px solid #ccd3da;border-radius:10px;margin:18px 0;padding:18px}
    label{display:block;margin:12px 0 5px;font-weight:650}input,select{box-sizing:border-box;width:100%;padding:10px;border:1px solid #aeb8c2;border-radius:7px;background:#fff;color:#17202a}
    .row{display:grid;grid-template-columns:2fr 1fr;gap:12px}.error{background:#ffe7e7;color:#8b1111;padding:12px;border-radius:8px}
    button{border:0;border-radius:8px;background:#1769aa;color:#fff;padding:12px 20px;font-weight:700;cursor:pointer}
    small{color:#5e6b76}.requirement{padding:10px 12px;border-radius:8px;background:#e9f7ef}.requirement.missing{background:#ffe7e7;color:#8b1111}@media(prefers-color-scheme:dark){body{background:#111820;color:#e9eef2}main{background:#1b2530}input,select{background:#111820;color:#e9eef2;border-color:#52606d}}
  </style>
  <script>function toggleDb(){document.getElementById('mysql').hidden=document.getElementById('db_driver').value!=='mysql'}</script>
</head>
<body onload="toggleDb()">
<main>
  <h1>Wikiman 설치</h1>
  <p>데이터베이스와 첫 관리자 계정을 설정합니다. 완료 전에는 Wikiman 프론트엔드를 제공하지 않습니다.</p>
  <p class="requirement<?= extension_loaded("gd") ? "" : " missing" ?>">
    PHP GD: <?= extension_loaded("gd") ? "사용 가능" : "설치 필요 (JPEG/PNG/GIF/WebP 썸네일 생성)" ?>
  </p>
  <?php if ($error !== ""): ?><div class="error"><?= $e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="token" value="<?= $e($token) ?>">
    <?php if (trim((string) getenv("WIKIMAN_INSTALL_TOKEN")) !== ""): ?>
    <fieldset>
      <legend>설치 토큰</legend>
      <label for="install_token">WIKIMAN_INSTALL_TOKEN</label>
      <input id="install_token" type="password" name="install_token" required value="">
      <small>서버에 설정된 설치 토큰을 입력하세요.</small>
    </fieldset>
    <?php endif; ?>
    <fieldset>
      <legend>사이트</legend>
      <label for="site_title">사이트 제목</label>
      <input id="site_title" name="site_title" maxlength="80" required value="<?= $e($input["site_title"] ?? "Wikiman") ?>">
    </fieldset>
    <fieldset>
      <legend>관리자</legend>
      <label for="username">관리자 아이디</label>
      <input id="username" name="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9_]+" required value="<?= $e($input["username"] ?? "") ?>">
      <label for="password">비밀번호</label>
      <input id="password" type="password" name="password" minlength="8" required>
      <label for="password_confirm">비밀번호 확인</label>
      <input id="password_confirm" type="password" name="password_confirm" minlength="8" required>
    </fieldset>
    <fieldset>
      <legend>데이터베이스</legend>
      <label for="db_driver">종류</label>
      <select id="db_driver" name="db_driver" onchange="toggleDb()">
        <option value="sqlite" <?= $driver === "sqlite" ? "selected" : "" ?>>SQLite</option>
        <option value="mysql" <?= $driver === "mysql" ? "selected" : "" ?>>MySQL / MariaDB</option>
      </select>
      <small>SQLite는 별도 DB 서버 없이 data/wiki.db를 사용합니다.</small>
      <div id="mysql">
        <div class="row">
          <div><label for="mysql_host">호스트</label><input id="mysql_host" name="mysql_host" value="<?= $e($input["mysql_host"] ?? "127.0.0.1") ?>"></div>
          <div><label for="mysql_port">포트</label><input id="mysql_port" name="mysql_port" type="number" value="<?= $e($input["mysql_port"] ?? "3306") ?>"></div>
        </div>
        <label for="mysql_database">데이터베이스 이름</label>
        <input id="mysql_database" name="mysql_database" value="<?= $e($input["mysql_database"] ?? "wikiman") ?>">
        <label for="mysql_username">DB 사용자</label>
        <input id="mysql_username" name="mysql_username" value="<?= $e($input["mysql_username"] ?? "") ?>">
        <label for="mysql_password">DB 비밀번호</label>
        <input id="mysql_password" type="password" name="mysql_password">
      </div>
    </fieldset>
    <button type="submit">설치하기</button>
  </form>
</main>
</body>
</html>
<?php
    exit;
}
