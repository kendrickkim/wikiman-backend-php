<?php

/***
 * 설정 조회
 * @ METHOD : GET
 * @ URL : /settings
 * @ RETURN
 *      사이트 제목·테마·에디터 기본값 등 프론트가 쓰는 설정 전체
 */
#[_GET_("/settings")]
function wiki_setting_get()
{
    return wiki_ok(wiki_settings(wiki_current_user()));
}

/***
 * 설정 변경
 * @ METHOD : PATCH
 * @ URL : /settings
 * @ REQUEST BODY : 변경할 키만 보낸다
 * @ RETURN
 *      변경 후 설정 전체
 */
#[_PATCH_("/settings"), WIKI_WRITER]
function wiki_setting_update()
{
    foreach (wiki_setting_schema() as $input => $rule) {
        if (wiki_has($input)) {
            list($storage_key, $validate) = $rule;
            wiki_save_setting($storage_key, $validate(wiki_input($input)));
        }
    }

    return wiki_ok(wiki_settings(wiki_current_user()));
}

/***
 * 상단 메뉴 편집 화면 데이터
 * @ METHOD : GET
 * @ URL : /settings/top-menu
 * @ RETURN
 *      items : [array] 현재 메뉴
 *      posts : [array] 메뉴에 연결할 수 있는 글 목록
 */
#[_GET_("/settings/top-menu"), WIKI_WRITER]
function wiki_setting_top_menu_get()
{
    $rows = wiki_db()->query(
        "SELECT id, title, status, visibility FROM posts
         WHERE deleted_at IS NULL ORDER BY updated_at DESC, id DESC"
    )->fetchAll();

    return wiki_ok([
        "items" => wiki_top_menu_items(wiki_require_writer()),
        "posts" => array_map(function ($row) {
            return [
                "id" => (int) $row["id"],
                "title" => (string) $row["title"],
                "status" => (string) $row["status"],
                "visibility" => (string) $row["visibility"],
            ];
        }, $rows),
    ]);
}

/***
 * 상단 메뉴 저장 (최대 20개)
 * @ METHOD : PUT
 * @ URL : /settings/top-menu
 * @ REQUEST BODY
 *      items : [array] { label, postId } 또는 { label, url }
 * @ RETURN
 *      items : [array] 저장된 메뉴
 */
#[_PUT_("/settings/top-menu"), WIKI_WRITER]
function wiki_setting_top_menu_put()
{
    $input = wiki_input("items", []);
    if (!is_array($input) || count($input) > 20) {
        wiki_abort(400, "상단 메뉴는 최대 20개까지 설정할 수 있습니다.");
    }

    $items = [];
    $seen = [];
    foreach ($input as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $label = trim((string) ($entry["label"] ?? ""));
        if ($label === "" || mb_strlen($label) > 30) {
            wiki_abort(400, "메뉴 이름은 1~30자로 입력하세요.");
        }

        $post_id = isset($entry["postId"]) && (int) $entry["postId"] > 0 ? (int) $entry["postId"] : null;
        $url = $post_id === null ? trim((string) ($entry["url"] ?? "")) : null;

        if ($post_id !== null) {
            $stmt = wiki_db()->prepare("SELECT id FROM posts WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$post_id]);
            if (!$stmt->fetch()) {
                wiki_abort(400, "메뉴에 연결할 글을 찾을 수 없습니다.");
            }
            $unique = "post:" . $post_id;
        } else {
            if ($url === "" || mb_strlen($url) > 500
                || (!str_starts_with($url, "/") && !filter_var($url, FILTER_VALIDATE_URL))) {
                wiki_abort(400, "올바른 내부 경로 또는 HTTP(S) 주소를 입력하세요.");
            }
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($scheme !== null && !in_array(strtolower($scheme), ["http", "https"], true)) {
                wiki_abort(400, "HTTP(S) 주소만 사용할 수 있습니다.");
            }
            $unique = "url:" . $url;
        }

        if (isset($seen[$unique])) {
            wiki_abort(400, "같은 글 또는 주소를 중복해서 등록할 수 없습니다.");
        }
        $seen[$unique] = true;

        $items[] = ["label" => $label, "postId" => $post_id, "url" => $url];
    }

    wiki_transaction(function ( PDO $db ) use ($items) {
        $db->exec("DELETE FROM top_menu_items");
        $stmt = $db->prepare("INSERT INTO top_menu_items (label, post_id, url, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($items as $order => $item) {
            $stmt->execute([$item["label"], $item["postId"], $item["url"], $order]);
        }
    });

    return wiki_ok(["items" => wiki_top_menu_items(wiki_current_user())]);
}
