<?php

/**
 * Authorization 헤더(Bearer) 또는 wikiman_token 쿠키에서 JWT 를 꺼낸다.
 */
function wiki_request_token()
{
    $header = "";
    $headers = apache_request_headers_insensitive();
    if (isset($headers["authorization"])) {
        $header = (string) $headers["authorization"];
    } else if (isset($_SERVER["HTTP_AUTHORIZATION"])) {
        $header = (string) $_SERVER["HTTP_AUTHORIZATION"];
    } else if (isset($_SERVER["REDIRECT_HTTP_AUTHORIZATION"])) {
        $header = (string) $_SERVER["REDIRECT_HTTP_AUTHORIZATION"];
    }

    if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
        return trim($matches[1]);
    }

    return trim((string) ($_COOKIE["wikiman_token"] ?? ""));
}

/**
 * 토큰이 유효하면 payload, 아니면 null. 비로그인 열람도 허용하므로 예외를 던지지 않는다.
 */
function wiki_current_user()
{
    static $resolved = false;
    static $user = null;

    if ($resolved) {
        return $user;
    }

    $resolved = true;
    $token = wiki_request_token();
    $user = $token === "" ? null : WIKI_JWT::Verify($token, wiki_config("jwt_secret"));
    return $user;
}

function wiki_require_login()
{
    $user = wiki_current_user();
    if (!$user) {
        wiki_abort(401, wiki_request_token() === ""
            ? "로그인이 필요합니다."
            : "세션이 만료되었습니다. 다시 로그인하세요.");
    }
    return $user;
}

/**
 * 토큰의 role 만 믿지 않고 DB 의 현재 권한을 다시 확인한다.
 */
function wiki_require_writer()
{
    static $writer = null;
    if ($writer !== null) {
        return $writer;
    }

    $user = wiki_require_login();

    $stmt = wiki_db()->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([(int) $user["id"]]);
    $row = $stmt->fetch();

    if (!$row || $row["role"] !== "writer" || ($user["canWrite"] ?? false) !== true) {
        wiki_abort(403, "글 작성은 위키 작성자만 할 수 있습니다.");
    }

    $writer = wiki_public_user($row);
    return $writer;
}

function wiki_public_user( $row )
{
    $role = ($row["role"] ?? "") === "writer" ? "writer" : "reader";
    return [
        "id" => (int) $row["id"],
        "username" => (string) $row["username"],
        "role" => $role,
        "canWrite" => $role === "writer",
    ];
}
