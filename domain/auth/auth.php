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
    if (!wiki_config("allow_register") || wiki_writer_exists()) {
        wiki_abort(403, "이 위키는 작성자 한 명만 가입할 수 있습니다.");
    }

    $username = trim((string) wiki_input("username", ""));
    $password = (string) wiki_input("password", "");

    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        wiki_abort(400, "아이디는 3~32자의 영문, 숫자, 밑줄만 사용할 수 있습니다.");
    }
    if (strlen($password) < 6) {
        wiki_abort(400, "비밀번호는 6자 이상이어야 합니다.");
    }

    $db = wiki_db();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        wiki_abort(409, "이미 사용 중인 아이디입니다.");
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'writer')");
        $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT, ["cost" => 10])]);
    } catch ( PDOException $e ) {
        if (strpos($e->getMessage(), "UNIQUE") !== false) {
            wiki_abort(409, "이미 사용 중인 아이디입니다.");
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
        wiki_abort(401, "아이디 또는 비밀번호가 올바르지 않습니다.");
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
        wiki_abort(401, "사용자를 찾을 수 없습니다.");
    }

    return wiki_ok(["user" => wiki_public_user($row)]);
}

function wiki_writer_exists()
{
    return wiki_db()->query("SELECT 1 FROM users WHERE role = 'writer' LIMIT 1")->fetchColumn() !== false;
}
