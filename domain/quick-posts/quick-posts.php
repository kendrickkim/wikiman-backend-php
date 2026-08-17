<?php

/***
 * 간단 포스트 목록 (본인 것만)
 * @ METHOD : GET
 * @ URL : /quick-posts
 * @ RETURN
 *      quickPosts : [array] 간단 포스트 목록
 */
#[_GET_("/quick-posts"), WIKI_WRITER]
function wiki_quick_post_list()
{
    $user = wiki_require_writer();

    $stmt = wiki_db()->prepare(
        "SELECT id, content, created_at, updated_at FROM quick_posts
         WHERE author_id = ? ORDER BY updated_at DESC, id DESC"
    );
    $stmt->execute([$user["id"]]);

    return wiki_ok(["quickPosts" => array_map("wiki_quick_post_map", $stmt->fetchAll())]);
}

/***
 * 간단 포스트 상세
 * @ METHOD : GET
 * @ URL : /quick-posts/{id}
 * @ RETURN
 *      quickPost : [object] 간단 포스트
 */
#[_GET_("/quick-posts/{id}"), WIKI_WRITER]
function wiki_quick_post_detail()
{
    return wiki_ok(["quickPost" => wiki_quick_post_map(wiki_owned_quick_post(wiki_uarg_int("id")))]);
}

/***
 * 간단 포스트 작성
 * @ METHOD : POST
 * @ URL : /quick-posts
 * @ REQUEST BODY
 *      content : [string] 내용
 * @ RETURN
 *      quickPost : [object] 만들어진 간단 포스트
 */
#[_POST_("/quick-posts"), WIKI_WRITER]
function wiki_quick_post_create()
{
    $user = wiki_require_writer();
    $db = wiki_db();

    $stmt = $db->prepare("INSERT INTO quick_posts (author_id, content) VALUES (?, ?)");
    $stmt->execute([$user["id"], wiki_quick_content(wiki_input("content", ""))]);

    return wiki_ok([
        "quickPost" => wiki_quick_post_map(wiki_owned_quick_post((int) $db->lastInsertId())),
    ], 201);
}

/***
 * 간단 포스트 수정
 * @ METHOD : PATCH
 * @ URL : /quick-posts/{id}
 * @ RETURN
 *      quickPost : [object] 수정된 간단 포스트
 */
#[_PATCH_("/quick-posts/{id}"), WIKI_WRITER]
function wiki_quick_post_update()
{
    $id = wiki_uarg_int("id");
    wiki_owned_quick_post($id);

    $stmt = wiki_db()->prepare("UPDATE quick_posts SET content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([wiki_quick_content(wiki_input("content", "")), $id]);

    return wiki_ok(["quickPost" => wiki_quick_post_map(wiki_owned_quick_post($id))]);
}

/***
 * 간단 포스트 삭제
 * @ METHOD : DELETE
 * @ URL : /quick-posts/{id}
 * @ RETURN
 *      ok : [bool] 성공 여부
 */
#[_DELETE_("/quick-posts/{id}"), WIKI_WRITER]
function wiki_quick_post_delete()
{
    $id = wiki_uarg_int("id");
    wiki_owned_quick_post($id);

    $stmt = wiki_db()->prepare("DELETE FROM quick_posts WHERE id = ?");
    $stmt->execute([$id]);

    return wiki_ok(["ok" => true]);
}

/***
 * 간단 포스트를 정식 글(초안)로 옮기기
 * @ METHOD : POST
 * @ URL : /quick-posts/{id}/promote
 * @ REQUEST BODY
 *      editorType : [string] 설정이 ask 일 때 사용할 에디터
 *      keepSource : [bool] 설정이 ask 일 때 원본 유지 여부
 * @ RETURN
 *      post : [object] 만들어진 글
 *      sourceKept : [bool] 간단 포스트 유지 여부
 */
#[_POST_("/quick-posts/{id}/promote"), WIKI_WRITER]
function wiki_quick_post_promote()
{
    $user = wiki_require_writer();
    $id = wiki_uarg_int("id");
    $quick = wiki_owned_quick_post($id);

    $content = trim((string) $quick["content"]);
    if ($content === "") {
        wiki_abort(400, "QUICK_POST_EMPTY_PROMOTE");
    }

    $settings = wiki_settings();
    $editor = $settings["quickPostPromoteEditor"] === "ask"
        ? wiki_normalize_editor(wiki_input("editorType", "textarea"))
        : wiki_normalize_editor($settings["quickPostPromoteEditor"]);

    $mode = $settings["quickPostPromoteSourceMode"];
    $keep = $mode === "keep" || ($mode === "ask" && wiki_input("keepSource", false) === true);

    $source_editor = $settings["quickPostEditor"] ?? "tui";
    $post_id = wiki_transaction(function ( PDO $db ) use ($user, $editor, $content, $keep, $id, $source_editor) {
        $stmt = $db->prepare(
            "INSERT INTO posts (title, slug, category_id, author_id, visibility, status, editor_type, content)
             VALUES ('', ?, NULL, ?, 'public', 'draft', ?, '')"
        );
        $stmt->execute([wiki_slugify(""), $user["id"], $editor]);
        $post_id = (int) $db->lastInsertId();

        $stmt = $db->prepare("UPDATE posts SET content = ? WHERE id = ?");
        $stmt->execute([
            wiki_prepare_content(
                $editor,
                wiki_quick_content_for_editor($content, $editor, $source_editor),
                $post_id
            ),
            $post_id,
        ]);

        wiki_sync_upload_refs($db, $post_id);

        if (!$keep) {
            $stmt = $db->prepare("DELETE FROM quick_posts WHERE id = ?");
            $stmt->execute([$id]);
        }

        return $post_id;
    });

    return wiki_ok([
        "sourceKept" => $keep,
        "post" => wiki_post_map(wiki_load_post($post_id), true),
    ], 201);
}

function wiki_quick_post_map( $row )
{
    return [
        "id" => (int) $row["id"],
        "content" => (string) ($row["content"] ?? ""),
        "createdAt" => (string) $row["created_at"],
        "updatedAt" => (string) $row["updated_at"],
    ];
}

/**
 * 본인 소유가 아니면 존재 여부도 알리지 않고 404 로 끝낸다.
 */
function wiki_owned_quick_post( $id )
{
    $user = wiki_require_writer();

    $stmt = wiki_db()->prepare("SELECT * FROM quick_posts WHERE id = ? AND author_id = ?");
    $stmt->execute([(int) $id, (int) $user["id"]]);
    $row = $stmt->fetch();

    if (!$row) {
        wiki_abort(404, "QUICK_POST_NOT_FOUND");
    }

    return $row;
}

function wiki_quick_content( $value )
{
    $content = trim((string) $value);
    if ($content === "") {
        wiki_abort(400, "QUICK_POST_CONTENT_REQUIRED");
    }
    return $content;
}

/**
 * 간단 포스트 에디터 형식을 대상 글 에디터 형식으로 옮긴다.
 */
function wiki_quick_content_for_editor( $content, $editor, $source_editor = "tui" )
{
    return wiki_convert_editor_content($content, $source_editor, $editor);
}
