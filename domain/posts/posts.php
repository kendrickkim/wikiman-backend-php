<?php

/***
 * 글 목록
 * @ METHOD : GET
 * @ URL : /posts
 * @ QUERY STRINGS
 *      categoryId : [number|uncategorized] 카테고리 (하위 포함)
 *      status : [string] draft | published
 *      keyword : [string] 키워드 정확히 일치
 *      q : [string] 제목·본문·키워드 검색
 *      page : [number] 1부터
 *      pageSize : [number] 10 | 20 | 50 | 100
 * @ RETURN
 *      posts : [array] 글 목록
 *      total, page, pageSize : [number] 페이지 정보
 */
#[_GET_("/posts")]
function wiki_post_list()
{
    $db = wiki_db();
    list($where, $params) = wiki_visible_post_filter(wiki_current_user());

    $category_id = wiki_query("categoryId", wiki_query("category_id"));
    if ($category_id === "uncategorized" || $category_id === "0") {
        $where[] = "posts.category_id IS NULL";
    } else if (filter_var($category_id, FILTER_VALIDATE_INT) !== false && (int) $category_id > 0) {
        $ids = wiki_category_descendants((int) $category_id);
        $where[] = "posts.category_id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")";
        foreach ($ids as $id) {
            $params[] = $id;
        }
    }

    $status = (string) wiki_query("status", "");
    if (in_array($status, ["draft", "published"], true)) {
        $where[] = "posts.status = ?";
        $params[] = $status;
    }

    $keyword = trim((string) wiki_query("keyword", ""));
    if ($keyword !== "") {
        $where[] = "EXISTS (SELECT 1 FROM post_keywords pk WHERE pk.post_id = posts.id AND lower(pk.keyword) = lower(?))";
        $params[] = $keyword;
    }

    $q = trim((string) wiki_query("q", ""));
    if ($q !== "") {
        $where[] = "(posts.title LIKE ? OR posts.content LIKE ? OR EXISTS
            (SELECT 1 FROM post_keywords pk WHERE pk.post_id = posts.id AND pk.keyword LIKE ?))";
        $like = "%" . str_replace(["%", "_"], "", $q) . "%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $requested_size = (int) wiki_query("pageSize", wiki_query("limit", 10));
    $page_size = in_array($requested_size, [10, 20, 50, 100], true) ? $requested_size : 10;
    $requested_page = max(1, (int) wiki_query("page", 1));

    $where_sql = implode(" AND ", array_map(function ($item) {
        return "(" . $item . ")";
    }, $where));

    $count = $db->prepare("SELECT COUNT(*) FROM posts WHERE " . $where_sql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $page = min($requested_page, max(1, (int) ceil($total / $page_size)));

    $stmt = $db->prepare(wiki_post_select() . " WHERE " . $where_sql
        . " ORDER BY posts.created_at DESC, posts.id DESC LIMIT ? OFFSET ?");

    $index = 1;
    foreach ($params as $value) {
        $stmt->bindValue($index++, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue($index++, $page_size, PDO::PARAM_INT);
    $stmt->bindValue($index, ($page - 1) * $page_size, PDO::PARAM_INT);
    $stmt->execute();

    return wiki_ok([
        "posts" => array_map(function ($row) {
            return wiki_post_map($row);
        }, $stmt->fetchAll()),
        "total" => $total,
        "page" => $page,
        "pageSize" => $page_size,
    ]);
}

/***
 * 키워드 목록
 * @ METHOD : GET
 * @ URL : /posts/keywords
 * @ RETURN
 *      keywords : [array] { name, count }
 */
#[_GET_("/posts/keywords")]
function wiki_post_keyword_list()
{
    list($where, $params) = wiki_visible_post_filter(wiki_current_user());

    $q = trim(str_replace(["%", "_"], "", (string) wiki_query("q", "")));
    if ($q !== "") {
        $where[] = "pk.keyword LIKE ?";
        $params[] = "%" . $q . "%";
    }

    $stmt = wiki_db()->prepare(
        "SELECT pk.keyword, COUNT(*) AS count FROM post_keywords pk
         JOIN posts ON posts.id = pk.post_id WHERE " . implode(" AND ", $where)
        . " GROUP BY lower(pk.keyword) ORDER BY count DESC, pk.keyword COLLATE NOCASE ASC LIMIT 500"
    );
    $stmt->execute($params);

    return wiki_ok(["keywords" => array_map(function ($row) {
        return ["name" => (string) $row["keyword"], "count" => (int) $row["count"]];
    }, $stmt->fetchAll())]);
}

/***
 * 홈페이지에 노출되는 글
 * @ METHOD : GET
 * @ URL : /posts/homepage
 * @ RETURN
 *      posts : [array] 본문을 포함한 글 목록
 */
#[_GET_("/posts/homepage")]
function wiki_post_homepage()
{
    $rows = wiki_db()->query(wiki_post_select(true) .
        " WHERE posts.deleted_at IS NULL AND homepage_posts.post_id IS NOT NULL
          ORDER BY homepage_posts.sort_order ASC")->fetchAll();

    $user = wiki_current_user();
    $rows = array_values(array_filter($rows, function ($row) use ($user) {
        return wiki_can_read_post($row, $user);
    }));

    return wiki_ok(["posts" => array_map(function ($row) {
        return wiki_post_map($row, true);
    }, $rows)]);
}

/***
 * 홈페이지 글 순서 저장
 * @ METHOD : PUT
 * @ URL : /posts/homepage/order
 * @ REQUEST BODY
 *      postIds : [array] 글 id 순서
 * @ RETURN
 *      homePostIds : [array] 저장된 순서
 *      hasHomepage : [bool] 홈 화면 사용 여부
 */
#[_PUT_("/posts/homepage/order"), WIKI_WRITER]
function wiki_post_homepage_order()
{
    $input = wiki_input("postIds", wiki_input("homePostIds", []));
    $ids = array_values(array_unique(array_filter(
        array_map("intval", is_array($input) ? $input : []),
        function ($id) {
            return $id > 0;
        }
    )));

    if ($ids) {
        $stmt = wiki_db()->prepare(
            "SELECT id FROM posts WHERE id IN (" . implode(",", array_fill(0, count($ids), "?")) . ")"
        );
        $stmt->execute($ids);
        if (count($stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
            wiki_abort(400, "홈페이지 글을 찾을 수 없습니다.");
        }
    }

    wiki_transaction(function ( PDO $db ) use ($ids) {
        $db->exec("DELETE FROM homepage_posts");
        $insert = $db->prepare("INSERT INTO homepage_posts (post_id, sort_order) VALUES (?, ?)");
        foreach ($ids as $order => $id) {
            $insert->execute([$id, $order]);
        }
    });

    return wiki_ok(["homePostIds" => $ids, "hasHomepage" => count($ids) > 0]);
}

/***
 * 휴지통 목록
 * @ METHOD : GET
 * @ URL : /posts/trash
 * @ RETURN
 *      posts : [array] 삭제된 글 목록
 */
#[_GET_("/posts/trash"), WIKI_WRITER]
function wiki_post_trash_list()
{
    $rows = wiki_db()->query(wiki_post_select() .
        " WHERE posts.deleted_at IS NOT NULL ORDER BY posts.deleted_at DESC, posts.id DESC")->fetchAll();

    return wiki_ok(["posts" => array_map(function ($row) {
        return wiki_post_map($row);
    }, $rows)]);
}

/***
 * 휴지통 비우기
 * @ METHOD : DELETE
 * @ URL : /posts/trash
 * @ RETURN
 *      ok : [bool] 성공 여부
 *      deleted : [number] 삭제된 글 수
 */
#[_DELETE_("/posts/trash"), WIKI_WRITER]
function wiki_post_trash_empty()
{
    $db = wiki_db();
    $rows = $db->query("SELECT id, content FROM posts WHERE deleted_at IS NOT NULL")->fetchAll();

    $names = [];
    foreach ($rows as $row) {
        $names = array_merge($names, wiki_content_upload_names((string) $row["content"]));
        $stmt = $db->prepare("SELECT stored_name FROM post_attachments WHERE post_id = ?");
        $stmt->execute([(int) $row["id"]]);
        $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $db->exec("DELETE FROM posts WHERE deleted_at IS NOT NULL");
    wiki_unlink_unused_uploads($names);

    return wiki_ok(["ok" => true, "deleted" => count($rows)]);
}

/***
 * 휴지통 복원
 * @ METHOD : POST
 * @ URL : /posts/{id}/restore
 * @ RETURN
 *      post : [object] 복원된 글
 */
#[_POST_("/posts/{id}/restore"), WIKI_WRITER]
function wiki_post_restore()
{
    $db = wiki_db();
    $id = wiki_uarg_int("id");

    $stmt = $db->prepare("SELECT id FROM posts WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        wiki_abort(404, "휴지통에서 글을 찾을 수 없습니다.");
    }

    $stmt = $db->prepare("UPDATE posts SET deleted_at = NULL, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$id]);

    return wiki_ok(["post" => wiki_post_map(wiki_load_post($id), true)]);
}

/***
 * 영구 삭제
 * @ METHOD : DELETE
 * @ URL : /posts/{id}/permanent
 * @ RETURN
 *      ok : [bool] 성공 여부
 */
#[_DELETE_("/posts/{id}/permanent"), WIKI_WRITER]
function wiki_post_delete_permanent()
{
    $db = wiki_db();
    $id = wiki_uarg_int("id");

    $stmt = $db->prepare("SELECT content FROM posts WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    $content = $stmt->fetchColumn();
    if ($content === false) {
        wiki_abort(404, "휴지통에서 글을 찾을 수 없습니다.");
    }

    $names = wiki_content_upload_names((string) $content);
    $stmt = $db->prepare("SELECT stored_name FROM post_attachments WHERE post_id = ?");
    $stmt->execute([$id]);
    $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $db->prepare("DELETE FROM posts WHERE id = ? AND deleted_at IS NOT NULL");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        wiki_abort(404, "휴지통에서 글을 찾을 수 없습니다.");
    }

    wiki_unlink_unused_uploads($names);
    return wiki_ok(["ok" => true]);
}

/***
 * 글 본문 안 이미지·파일
 * @ METHOD : GET
 * @ URL : /posts/{id}/files/{name}
 */
#[_GET_("/posts/{id}/files/{name}")]
function wiki_post_file()
{
    $name = basename((string) wiki_uarg("name"));
    $path = wiki_upload_path($name);

    if (!$path || !wiki_can_access_upload($name, wiki_uarg_int("id"))) {
        wiki_abort(404, "파일을 찾을 수 없습니다.");
    }

    wiki_send_file($path);
}

/***
 * 첨부파일 내려받기
 * @ METHOD : GET
 * @ URL : /posts/{id}/attachments/{attachmentId}
 */
#[_GET_("/posts/{id}/attachments/{attachmentId}")]
function wiki_post_attachment_download()
{
    $db = wiki_db();
    $post_id = wiki_uarg_int("id");

    $stmt = $db->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    if (!wiki_can_read_post($stmt->fetch() ?: null, wiki_current_user())) {
        wiki_abort(404, "파일을 찾을 수 없습니다.");
    }

    $stmt = $db->prepare("SELECT * FROM post_attachments WHERE id = ? AND post_id = ?");
    $stmt->execute([wiki_uarg_int("attachmentId"), $post_id]);
    $attachment = $stmt->fetch();

    $path = $attachment ? wiki_upload_path((string) $attachment["stored_name"]) : null;
    if (!$attachment || !$path) {
        wiki_abort(404, "파일을 찾을 수 없습니다.");
    }

    wiki_send_file($path, (string) $attachment["original_name"], (string) $attachment["mime_type"]);
}

/***
 * 글 상세
 * @ METHOD : GET
 * @ URL : /posts/{id}
 * @ RETURN
 *      post : [object] 본문·첨부 포함
 */
#[_GET_("/posts/{id}")]
function wiki_post_detail()
{
    $row = wiki_load_post(wiki_uarg_int("id"));
    if (!wiki_can_read_post($row, wiki_current_user())) {
        wiki_abort(404, "글을 찾을 수 없습니다.");
    }

    return wiki_ok(["post" => wiki_post_map($row, true)]);
}

/***
 * 글 작성
 * @ METHOD : POST
 * @ URL : /posts
 * @ RETURN
 *      post : [object] 만들어진 글
 */
#[_POST_("/posts"), WIKI_WRITER]
function wiki_post_create()
{
    $user = wiki_require_writer();

    $title = trim((string) wiki_input("title", ""));
    $category_id = wiki_parse_id(wiki_input("categoryId", wiki_input("category_id")));
    if ($category_id !== null && !wiki_require_category($category_id)) {
        wiki_abort(400, "카테고리를 찾을 수 없습니다.");
    }

    $visibility = wiki_input("visibility") === "private" ? "private" : "public";
    $status = wiki_input("status") === "published" ? "published" : "draft";
    $editor = wiki_normalize_editor(wiki_input("editorType", "ckeditor"));
    $raw_content = wiki_input("content", "");
    $keywords = wiki_normalize_keywords(wiki_input("keywords", []));
    $attachments = wiki_normalize_attachments(wiki_input("attachments", []));
    $has_homepage = wiki_has("isHomepage");
    $homepage = wiki_input("isHomepage");
    $homepage_sort = wiki_input("homepageSort");

    $id = wiki_transaction(function ( PDO $db ) use (
        $title, $category_id, $user, $visibility, $status, $editor,
        $raw_content, $keywords, $attachments, $has_homepage, $homepage, $homepage_sort
    ) {
        $stmt = $db->prepare(
            "INSERT INTO posts (title, slug, category_id, author_id, visibility, status, editor_type, content)
             VALUES (?, ?, ?, ?, ?, ?, ?, '')"
        );
        $stmt->execute([$title, wiki_slugify($title), $category_id, $user["id"], $visibility, $status, $editor]);
        $id = (int) $db->lastInsertId();

        $stmt = $db->prepare("UPDATE posts SET content = ? WHERE id = ?");
        $stmt->execute([wiki_prepare_content($editor, $raw_content, $id), $id]);

        wiki_replace_keywords($db, $id, $keywords);
        wiki_replace_attachments($db, $id, $attachments);
        if ($has_homepage) {
            wiki_apply_homepage($db, $id, $homepage, $homepage_sort);
        }

        return $id;
    });

    return wiki_ok(["post" => wiki_post_map(wiki_load_post($id), true)], 201);
}

/***
 * 글 수정
 * @ METHOD : PATCH
 * @ URL : /posts/{id}
 * @ RETURN
 *      post : [object] 수정된 글
 */
#[_PATCH_("/posts/{id}"), WIKI_WRITER]
function wiki_post_update()
{
    $db = wiki_db();
    $id = wiki_uarg_int("id");

    $stmt = $db->prepare("SELECT * FROM posts WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        wiki_abort(404, "글을 찾을 수 없습니다.");
    }

    $title = wiki_has("title") ? trim((string) wiki_input("title")) : (string) $existing["title"];

    $category_id = $existing["category_id"] === null ? null : (int) $existing["category_id"];
    if (wiki_has("categoryId") || wiki_has("category_id")) {
        $category_id = wiki_parse_id(wiki_input("categoryId", wiki_input("category_id")));
    }
    if ($category_id !== null && !wiki_require_category($category_id)) {
        wiki_abort(400, "카테고리를 찾을 수 없습니다.");
    }

    $visibility = wiki_has("visibility")
        ? (wiki_input("visibility") === "private" ? "private" : "public")
        : (string) $existing["visibility"];
    $status = wiki_has("status")
        ? (wiki_input("status") === "published" ? "published" : "draft")
        : (string) $existing["status"];
    $editor = wiki_has("editorType")
        ? wiki_normalize_editor(wiki_input("editorType"))
        : (string) $existing["editor_type"];
    $content = wiki_has("content")
        ? wiki_prepare_content($editor, wiki_input("content"), $id)
        : (string) $existing["content"];
    $slug = $title !== $existing["title"] ? wiki_slugify($title) : (string) $existing["slug"];

    $has_keywords = wiki_has("keywords");
    $keywords = wiki_normalize_keywords(wiki_input("keywords", []));
    $has_attachments = wiki_has("attachments");
    $attachments = wiki_normalize_attachments(wiki_input("attachments", []));
    $has_homepage = wiki_has("isHomepage");
    $homepage = wiki_input("isHomepage");
    $homepage_sort = wiki_input("homepageSort");

    $removed = wiki_transaction(function ( PDO $db ) use (
        $id, $title, $slug, $category_id, $visibility, $status, $editor, $content,
        $has_keywords, $keywords, $has_attachments, $attachments, $has_homepage, $homepage, $homepage_sort
    ) {
        $stmt = $db->prepare(
            "UPDATE posts SET title = ?, slug = ?, category_id = ?, visibility = ?, status = ?,
             editor_type = ?, content = ?, updated_at = datetime('now') WHERE id = ?"
        );
        $stmt->execute([$title, $slug, $category_id, $visibility, $status, $editor, $content, $id]);

        if ($has_keywords) {
            wiki_replace_keywords($db, $id, $keywords);
        }

        $removed = [];
        if ($has_attachments) {
            $removed = wiki_replace_attachments($db, $id, $attachments);
        } else {
            wiki_sync_upload_refs($db, $id);
        }

        if ($has_homepage) {
            wiki_apply_homepage($db, $id, $homepage, $homepage_sort);
        }

        return $removed;
    });

    wiki_unlink_unused_uploads($removed);

    return wiki_ok(["post" => wiki_post_map(wiki_load_post($id), true)]);
}

/***
 * 글 삭제 (휴지통으로 이동)
 * @ METHOD : DELETE
 * @ URL : /posts/{id}
 * @ RETURN
 *      ok : [bool] 성공 여부
 *      trashed : [bool] 휴지통 이동 여부
 */
#[_DELETE_("/posts/{id}"), WIKI_WRITER]
function wiki_post_delete()
{
    $db = wiki_db();
    $id = wiki_uarg_int("id");

    $stmt = $db->prepare("UPDATE posts SET deleted_at = datetime('now') WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        wiki_abort(404, "글을 찾을 수 없습니다.");
    }

    $stmt = $db->prepare("DELETE FROM homepage_posts WHERE post_id = ?");
    $stmt->execute([$id]);

    return wiki_ok(["ok" => true, "trashed" => true]);
}
