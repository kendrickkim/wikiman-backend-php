<?php

/***
 * 가입 가능 여부
 * @ METHOD : GET
 * @ URL : /auth/status
 * @ RETURN
 *      canRegister : [bool] 가입 가능 여부(작성자 계정이 없을 때만 true)
 */
#[_GET_("/auth/status")]
function wiki_auth_status()
{
    return wiki_ok(["canRegister" => wiki_config("allow_register") && !wiki_writer_exists()]);
}

/***
 * 작성자 가입 (최초 1회)
 * @ METHOD : POST
 * @ URL : /auth/register
 * @ REQUEST BODY
 *      username : [string] 3~32자 영문/숫자/밑줄
 *      password : [string] 6자 이상
 * @ RETURN
 *      token : [string] JWT
 *      user : [object] 사용자 정보
 */
#[_POST_("/auth/register")]
function wiki_auth_register()
{
    if (!wiki_is_installed()) {
        wiki_abort(503, "INSTALL_REQUIRED", ["installUrl" => "/install.php"]);
    }
    // 신규 설치의 첫 관리자는 install.php가 만든다. 등록 API는 복구·레거시용으로만 연다.
    if (!wiki_config("allow_register") || wiki_writer_exists()) {
        wiki_abort(403, "REGISTER_CLOSED");
    }

    $username = trim((string) wiki_input("username", ""));
    $password = (string) wiki_input("password", "");

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        wiki_abort(400, "USERNAME_INVALID");
    }
    if (strlen($password) < 6) {
        wiki_abort(400, "PASSWORD_TOO_SHORT");
    }

    $db = wiki_db();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        wiki_abort(409, "USERNAME_TAKEN");
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'writer')");
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT, ["cost" => 10])]);
    } catch ( PDOException $e ) {
        if (($e->errorInfo[0] ?? "") === "23000"
            || stripos($e->getMessage(), "unique") !== false
            || stripos($e->getMessage(), "duplicate") !== false) {
            wiki_abort(409, "USERNAME_TAKEN");
        }
        throw $e;
    }

    $user = wiki_public_user([
        "id" => (int) $db->lastInsertId(),
        "username" => $username,
        "role" => "writer",
    ]);

    return wiki_ok([
        "token" => WIKI_JWT::Sign($user, wiki_config("jwt_secret"), wiki_config("jwt_ttl")),
        "user" => $user,
    ], 201);
}

/***
 * 로그인
 * @ METHOD : POST
 * @ URL : /auth/login
 * @ REQUEST BODY
 *      username : [string] 아이디
 *      password : [string] 비밀번호
 * @ RETURN
 *      token : [string] JWT
 *      user : [object] 사용자 정보
 */
#[_POST_("/auth/login")]
function wiki_auth_login()
{
    $username = trim((string) wiki_input("username", ""));
    $password = (string) wiki_input("password", "");

    $stmt = wiki_db()->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, (string) $row["password_hash"])) {
        wiki_abort(401, "INVALID_CREDENTIALS");
    }

    $user = wiki_public_user($row);
    return wiki_ok([
        "token" => WIKI_JWT::Sign($user, wiki_config("jwt_secret"), wiki_config("jwt_ttl")),
        "user" => $user,
    ]);
}

/***
 * 내 정보
 * @ METHOD : GET
 * @ URL : /auth/me
 * @ RETURN
 *      user : [object] 사용자 정보
 */
#[_GET_("/auth/me"), WIKI_LOGIN]
function wiki_auth_me()
{
    $token_user = wiki_current_user();

    $stmt = wiki_db()->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([(int) $token_user["id"]]);
    $row = $stmt->fetch();

    if (!$row) {
        wiki_abort(401, "USER_NOT_FOUND");
    }

    return wiki_ok(["user" => wiki_public_user($row)]);
}

function wiki_writer_exists()
{
    return wiki_db()->query("SELECT 1 FROM users WHERE role = 'writer' LIMIT 1")->fetchColumn() !== false;
}
