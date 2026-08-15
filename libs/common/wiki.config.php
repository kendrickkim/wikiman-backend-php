<?php

/**
 * Wikiman 앱 설정. 환경변수가 없으면 앱 디렉터리 기준 기본값을 쓴다.
 */
function wiki_config( $key = null )
{
    static $config = null;

    if ($config === null) {
        $app_env = getenv("APP_ENV") ?: "development";
        $data_dir = rtrim((string) (getenv("WIKIMAN_DATA_DIR") ?: dirname(__DIR__, 2) . "/data"), "/");

        $config = [
            "env" => $app_env,
            "debug" => $app_env !== "production",
            "data_dir" => $data_dir,
            "database" => $data_dir . "/wiki.db",
            "uploads" => $data_dir . "/uploads",
            "jwt_secret" => wiki_jwt_secret($data_dir),
            "jwt_ttl" => 60 * 60 * 24 * 7,
            "allow_register" => strtolower((string) (getenv("ALLOW_REGISTER") ?: "true")) !== "false",
        ];
    }

    return $key === null ? $config : ($config[$key] ?? null);
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
        wiki_abort(500, "데이터 디렉터리를 만들 수 없습니다.");
    }
}
