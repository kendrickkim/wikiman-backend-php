<?php

/***
 * 카테고리 목록
 * @ METHOD : GET
 * @ URL : /categories
 * @ RETURN
 *      categories : [array] 카테고리 목록 (비로그인은 private 계열 제외)
 */
#[_GET_("/categories")]
function wiki_category_list()
{
    $rows = wiki_db()->query(
        "SELECT id, name, parent_id, sort_order, visibility, created_by, created_at
         FROM categories ORDER BY sort_order ASC, name ASC"
    )->fetchAll();

    if (!wiki_current_user()) {
        $hidden = array_flip(wiki_private_category_ids());
        $rows = array_values(array_filter($rows, function ($row) use ($hidden) {
            return !isset($hidden[(int) $row["id"]]);
        }));
    }

    return wiki_ok(["categories" => array_map("wiki_category_map", $rows)]);
}

/***
 * 카테고리 추가
 * @ METHOD : POST
 * @ URL : /categories
 * @ REQUEST BODY
 *      name : [string] 이름
 *      parentId : [number] 상위 카테고리 (없으면 null)
 *      visibility : [string] public | private
 * @ RETURN
 *      category : [object] 만들어진 카테고리
 */
#[_POST_("/categories"), WIKI_WRITER]
function wiki_category_create()
{
    $user = wiki_require_writer();

    $name = trim((string) wiki_input("name", ""));
    $parent_id = wiki_parse_id(wiki_input("parentId", wiki_input("parent_id")));
    $visibility = wiki_input("visibility") === "private" ? "private" : "public";

    if ($name === "") {
        wiki_abort(400, "카테고리 이름을 입력하세요.");
    }
    if ($parent_id !== null && !wiki_require_category($parent_id)) {
        wiki_abort(400, "상위 카테고리를 찾을 수 없습니다.");
    }

    $db = wiki_db();
    $stmt = $db->prepare(
        "INSERT INTO categories (name, parent_id, sort_order, visibility, created_by) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $parent_id, wiki_next_sort_order($parent_id), $visibility, $user["id"]]);

    return wiki_ok(["category" => wiki_category_map(wiki_find_category((int) $db->lastInsertId()))], 201);
}

/***
 * 카테고리 수정
 * @ METHOD : PATCH
 * @ URL : /categories/{id}
 * @ RETURN
 *      category : [object] 수정된 카테고리
 */
#[_PATCH_("/categories/{id}"), WIKI_WRITER]
function wiki_category_update()
{
    $id = wiki_uarg_int("id");
    $existing = wiki_find_category($id);
    if (!$existing) {
        wiki_abort(404, "카테고리를 찾을 수 없습니다.");
    }

    $name = wiki_has("name") ? trim((string) wiki_input("name")) : (string) $existing["name"];
    if ($name === "") {
        wiki_abort(400, "카테고리 이름을 입력하세요.");
    }

    $current_parent = $existing["parent_id"] === null ? null : (int) $existing["parent_id"];
    $has_parent = wiki_has("parentId") || wiki_has("parent_id");
    $parent_id = $has_parent
        ? wiki_parse_id(wiki_input("parentId", wiki_input("parent_id")))
        : $current_parent;

    if ($parent_id !== null && !wiki_require_category($parent_id)) {
        wiki_abort(400, "상위 카테고리를 찾을 수 없습니다.");
    }
    if (wiki_category_would_cycle($id, $parent_id)) {
        wiki_abort(400, "자기 자신 또는 하위 카테고리로는 이동할 수 없습니다.");
    }

    $has_sort = wiki_has("sortOrder") || wiki_has("sort_order");
    $sort_order = (int) wiki_input("sortOrder", wiki_input("sort_order", $existing["sort_order"]));
    if ($current_parent !== $parent_id && !$has_sort) {
        // 부모가 바뀌면 새 부모의 마지막 순서로 보낸다.
        $sort_order = wiki_next_sort_order($parent_id);
    }

    $visibility = wiki_has("visibility")
        ? (wiki_input("visibility") === "private" ? "private" : "public")
        : ($existing["visibility"] === "private" ? "private" : "public");

    $stmt = wiki_db()->prepare(
        "UPDATE categories SET name = ?, parent_id = ?, sort_order = ?, visibility = ? WHERE id = ?"
    );
    $stmt->execute([$name, $parent_id, $sort_order, $visibility, $id]);

    return wiki_ok(["category" => wiki_category_map(wiki_find_category($id))]);
}

/***
 * 카테고리 삭제 (하위 카테고리와 글은 상위로 올린다)
 * @ METHOD : DELETE
 * @ URL : /categories/{id}
 * @ RETURN
 *      ok : [bool] 성공 여부
 */
#[_DELETE_("/categories/{id}"), WIKI_WRITER]
function wiki_category_delete()
{
    $id = wiki_uarg_int("id");
    $existing = wiki_find_category($id);
    if (!$existing) {
        wiki_abort(404, "카테고리를 찾을 수 없습니다.");
    }

    wiki_transaction(function ( PDO $db ) use ($id, $existing) {
        $stmt = $db->prepare("UPDATE categories SET parent_id = ? WHERE parent_id = ?");
        $stmt->execute([$existing["parent_id"], $id]);

        $stmt = $db->prepare("UPDATE posts SET category_id = ? WHERE category_id = ?");
        $stmt->execute([$existing["parent_id"], $id]);

        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
    });

    return wiki_ok(["ok" => true]);
}

function wiki_find_category( $id )
{
    $stmt = wiki_db()->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}
