<?php

/**
 * 설치기가 생성한 data/config.php와 환경 변수를 합쳐 앱 설정을 만든다.
 */
function wiki_config( $key = null, $reload = false )
{
    static $config = null;
    if ($reload) $config = null;

    if ($config === null) {
        $app_env = getenv("APP_ENV") ?: "development";
        $data_dir = rtrim((string) (getenv("WIKIMAN_DATA_DIR") ?: dirname(__DIR__, 2) . "/data"), "/");
        $stored = wiki_stored_config($data_dir);
        $stored_db = is_array($stored["db"] ?? null) ? $stored["db"] : [];
        $driver = strtolower((string) (getenv("WIKIMAN_DB_DRIVER")
            ?: ($stored_db["driver"] ?? "sqlite")));
        if (!in_array($driver, ["sqlite", "mysql"], true)) $driver = "sqlite";

        $sqlite_path = (string) (getenv("WIKIMAN_SQLITE_PATH")
            ?: ($stored_db["path"] ?? ($data_dir . "/wiki.db")));
        $mysql_host = (string) (getenv("WIKIMAN_MYSQL_HOST") ?: ($stored_db["host"] ?? "127.0.0.1"));
        $mysql_port = (int) (getenv("WIKIMAN_MYSQL_PORT") ?: ($stored_db["port"] ?? 3306));
        $mysql_database = (string) (getenv("WIKIMAN_MYSQL_DATABASE") ?: ($stored_db["database"] ?? ""));
        $mysql_username = (string) (getenv("WIKIMAN_MYSQL_USERNAME") ?: ($stored_db["username"] ?? ""));
        $mysql_password_env = getenv("WIKIMAN_MYSQL_PASSWORD");
        $mysql_password = $mysql_password_env !== false
            ? (string) $mysql_password_env
            : (string) ($stored_db["password"] ?? "");
        $mysql_charset = (string) (getenv("WIKIMAN_MYSQL_CHARSET") ?: ($stored_db["charset"] ?? "utf8mb4"));
        $environment_configured = getenv("WIKIMAN_DB_DRIVER") !== false;
        $legacy_sqlite = is_file($data_dir . "/wiki.db");
        $installed = ($stored["installed"] ?? false) === true || $environment_configured || $legacy_sqlite;

        $config = [
            "env" => $app_env,
            "debug" => $app_env !== "production",
            "data_dir" => $data_dir,
            "config_file" => wiki_config_file($data_dir),
            "installed" => $installed,
            "db_driver" => $driver,
            "database" => $sqlite_path,
            "db" => [
                "driver" => $driver,
                "path" => $sqlite_path,
                "host" => $mysql_host,
                "port" => $mysql_port,
                "database" => $mysql_database,
                "username" => $mysql_username,
                "password" => $mysql_password,
                "charset" => preg_match('/^[a-zA-Z0-9_]+$/', $mysql_charset) ? $mysql_charset : "utf8mb4",
            ],
            "uploads" => $data_dir . "/uploads",
            "jwt_secret" => $installed ? wiki_jwt_secret($data_dir) : "",
            "jwt_ttl" => 60 * 60 * 24 * 7,
            "allow_register" => strtolower((string) (getenv("ALLOW_REGISTER") ?: "true")) !== "false",
            "site_title" => trim((string) ($stored["site_title"] ?? "Wikiman")) ?: "Wikiman",
        ];
    }

    return $key === null ? $config : ($config[$key] ?? null);
}

function wiki_reload_config()
{
    return wiki_config(null, true);
}

function wiki_config_file( $data_dir = null )
{
    $base = $data_dir ?: rtrim((string) (getenv("WIKIMAN_DATA_DIR")
        ?: dirname(__DIR__, 2) . "/data"), "/");
    return $base . "/config.php";
}

function wiki_stored_config( $data_dir = null )
{
    $file = wiki_config_file($data_dir);
    if (!is_file($file)) return [];
    try {
        $value = @include($file);
        return is_array($value) ? $value : [];
    } catch ( Throwable $error ) {
        error_log("wikiman: invalid config file - " . $error->getMessage());
        return [];
    }
}

function wiki_is_installed()
{
    return wiki_config("installed") === true;
}

function wiki_db_driver()
{
    return wiki_config("db_driver") === "mysql" ? "mysql" : "sqlite";
}

/**
 * JWT 서명 키. 환경변수가 없으면 data/.jwt-secret 에 만들어 재사용한다.
 */
function wiki_jwt_secret( $data_dir )
{
    $secret = trim((string) getenv("JWT_SECRET"));
    if ($secret !== "") {
        return $secret;
    }

    wiki_make_dir($data_dir);
    $secret_file = $data_dir . "/.jwt-secret";
    if (is_file($secret_file)) {
        return trim((string) file_get_contents($secret_file));
    }

    $secret = bin2hex(random_bytes(32));
    file_put_contents($secret_file, $secret, LOCK_EX);
    @chmod($secret_file, 0640);
    return $secret;
}

function wiki_make_dir( $path )
{
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
        wiki_abort(500, "DATA_DIRECTORY_CREATE_FAILED");
    }
}
