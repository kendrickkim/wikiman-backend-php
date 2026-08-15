<?php

const WIKI_EDITORS = ["textarea", "ckeditor", "summernote", "tui", "editorjs", "markdown", "html"];

function wiki_normalize_editor( $value )
{
    $editor = (string) $value;
    return in_array($editor, WIKI_EDITORS, true) ? $editor : "ckeditor";
}

function wiki_normalize_keywords( $value )
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    $seen = [];
    foreach ($value as $item) {
        $keyword = trim(mb_substr((string) $item, 0, 40));
        $key = mb_strtolower($keyword);
        if ($keyword === "" || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $keyword;
        if (count($out) >= 20) {
            break;
        }
    }

    return $out;
}

/**
 * HTML 계열 에디터 본문에서 실행 가능한 요소를 제거한다.
 */
function wiki_sanitize_content( $editor_type, $content )
{
    $value = (string) $content;
    if (!in_array($editor_type, ["ckeditor", "summernote", "html"], true)) {
        return $value;
    }

    $value = preg_replace('#<(script|iframe|object|embed|form|input|button|textarea|select|style)\b[^>]*>.*?</\1>#is', "", $value) ?? "";
    $value = preg_replace('#<(script|iframe|object|embed|form|input|button|textarea|select|style)\b[^>]*/?>#is', "", $value) ?? "";
    $value = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', "", $value) ?? "";
    $value = preg_replace('/\s+(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', ' $1="#"', $value) ?? "";

    return $value;
}

function wiki_slugify( $title )
{
    $base = mb_strtolower(trim((string) $title));
    $base = preg_replace('/[^\p{L}\p{N}]+/u', "-", $base) ?? "";
    $base = trim($base, "-");
    $base = mb_substr($base, 0, 60) ?: "post";
    return $base . "-" . base_convert((string) (int) (microtime(true) * 1000), 10, 36) . bin2hex(random_bytes(2));
}

function wiki_post_keywords( $post_id )
{
    $stmt = wiki_db()->prepare("SELECT keyword FROM post_keywords WHERE post_id = ? ORDER BY sort_order ASC");
    $stmt->execute([(int) $post_id]);
    return array_map(function ($row) {
        return (string) $row["keyword"];
    }, $stmt->fetchAll());
}

function wiki_post_attachments( $post_id )
{
    $post_id = (int) $post_id;
    $stmt = wiki_db()->prepare("SELECT * FROM post_attachments WHERE post_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$post_id]);

    return array_map(function ($row) use ($post_id) {
        return [
            "id" => (int) $row["id"],
            "storedName" => (string) $row["stored_name"],
            "originalName" => (string) $row["original_name"],
            "mimeType" => (string) $row["mime_type"],
            "size" => (int) $row["size"],
            "url" => "/api/posts/" . $post_id . "/files/" . rawurlencode((string) $row["stored_name"]),
            "downloadUrl" => "/api/posts/" . $post_id . "/attachments/" . (int) $row["id"],
        ];
    }, $stmt->fetchAll());
}

function wiki_post_map( $row, $include_content = false )
{
    $post = [
        "id" => (int) $row["id"],
        "title" => (string) $row["title"],
        "slug" => (string) $row["slug"],
        "categoryId" => $row["category_id"] === null ? null : (int) $row["category_id"],
        "categoryName" => $row["category_name"] ?? null,
        "authorId" => (int) $row["author_id"],
        "authorName" => (string) $row["author_name"],
        "visibility" => (string) $row["visibility"],
        "status" => (string) $row["status"],
        "editorType" => (string) $row["editor_type"],
        "keywords" => wiki_post_keywords((int) $row["id"]),
        "isHomepage" => $row["homepage_sort"] !== null,
        "homepageSort" => $row["homepage_sort"] === null ? null : (int) $row["homepage_sort"],
        "deletedAt" => $row["deleted_at"] ?? null,
        "createdAt" => (string) $row["created_at"],
        "updatedAt" => (string) $row["updated_at"],
    ];

    if ($include_content) {
        $post["content"] = wiki_sanitize_content((string) $row["editor_type"], $row["content"] ?? "");
        $post["attachments"] = wiki_post_attachments((int) $row["id"]);
    }

    return $post;
}

function wiki_post_select( $with_content = false )
{
    return "SELECT posts.id, posts.title, posts.slug, posts.category_id, posts.author_id,
            posts.visibility, posts.status, posts.editor_type, posts.created_at, posts.updated_at,
            posts.deleted_at" . ($with_content ? ", posts.content" : "") . ",
            users.username AS author_name, categories.name AS category_name,
            homepage_posts.sort_order AS homepage_sort
        FROM posts
        JOIN users ON users.id = posts.author_id
        LEFT JOIN categories ON categories.id = posts.category_id
        LEFT JOIN homepage_posts ON homepage_posts.post_id = posts.id";
}

function wiki_load_post( $id, $with_content = true )
{
    $stmt = wiki_db()->prepare(wiki_post_select($with_content) . " WHERE posts.id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch() ?: null;
}

/**
 * 초안·비공개 글은 작성자만, private 카테고리 글은 로그인 사용자만 읽을 수 있다.
 */
function wiki_can_read_post( $row, $user )
{
    if (!$row || $row["deleted_at"] !== null) {
        return false;
    }

    $owner = $user && (int) $user["id"] === (int) $row["author_id"];
    if ($row["status"] === "draft" && !$owner) {
        return false;
    }
    if ($row["visibility"] === "private" && !$owner) {
        return false;
    }

    return $user !== null || !wiki_category_requires_login($row["category_id"]);
}

/**
 * 비로그인 사용자에게 보일 수 있는 글로 제한하는 WHERE 절과 바인딩 값.
 */
function wiki_visible_post_filter( $user )
{
    $where = ["posts.deleted_at IS NULL"];
    $params = [];

    if ($user) {
        $where[] = "((posts.status = 'published' AND (posts.visibility = 'public' OR posts.author_id = ?))
            OR (posts.status = 'draft' AND posts.author_id = ?))";
        $params[] = (int) $user["id"];
        $params[] = (int) $user["id"];
    } else {
        $where[] = "posts.status = 'published' AND posts.visibility = 'public'";
        $hidden = wiki_private_category_ids();
        if ($hidden) {
            $where[] = "posts.category_id IS NULL OR posts.category_id NOT IN ("
                . implode(",", array_fill(0, count($hidden), "?")) . ")";
            foreach ($hidden as $id) {
                $params[] = $id;
            }
        }
    }

    return [$where, $params];
}

function wiki_replace_keywords( PDO $db, $post_id, $keywords )
{
    $stmt = $db->prepare("DELETE FROM post_keywords WHERE post_id = ?");
    $stmt->execute([(int) $post_id]);

    $insert = $db->prepare("INSERT INTO post_keywords (post_id, keyword, sort_order) VALUES (?, ?, ?)");
    foreach ($keywords as $order => $keyword) {
        $insert->execute([(int) $post_id, $keyword, $order]);
    }
}

/**
 * 본문에 실제로 존재하는 업로드 파일만 첨부로 인정한다.
 */
function wiki_normalize_attachments( $value )
{
    if (!is_array($value)) {
        return [];
    }

    $uploads = wiki_config("uploads");
    $result = [];
    $seen = [];

    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = basename((string) ($item["storedName"] ?? $item["stored_name"] ?? ""));
        if ($name === "" || isset($seen[$name]) || !is_file($uploads . "/" . $name)) {
            continue;
        }
        $seen[$name] = true;
        $original = mb_substr(trim((string) ($item["originalName"] ?? $item["original_name"] ?? $name)), 0, 200);
        $result[] = [
            "storedName" => $name,
            "originalName" => $original !== "" ? $original : $name,
            "mimeType" => mb_substr((string) ($item["mimeType"] ?? $item["mime_type"] ?? "application/octet-stream"), 0, 120),
            "size" => max(0, (int) ($item["size"] ?? filesize($uploads . "/" . $name))),
        ];
        if (count($result) >= 50) {
            break;
        }
    }

    return $result;
}

function wiki_content_upload_names( $content )
{
    preg_match_all('#/api/(?:posts/\d+/)?files/([^/?\#"\'<>\s]+)#u', (string) $content, $matches);
    return array_values(array_unique(array_map(function ($name) {
        return basename(rawurldecode($name));
    }, $matches[1] ?? [])));
}

/**
 * 업로드 직후의 /api/files/... 경로를 글 전용 경로로 바꾼다.
 */
function wiki_prepare_content( $editor_type, $content, $post_id )
{
    $sanitized = wiki_sanitize_content($editor_type, $content);
    $replaced = preg_replace_callback('#/api/files/([^/?\#"\'<>\s]+)#u', function ($match) use ($post_id) {
        return "/api/posts/" . (int) $post_id . "/files/" . $match[1];
    }, $sanitized);

    return $replaced ?? $sanitized;
}

function wiki_sync_upload_refs( PDO $db, $post_id )
{
    $post_id = (int) $post_id;

    $stmt = $db->prepare("DELETE FROM upload_refs WHERE post_id = ?");
    $stmt->execute([$post_id]);

    $insert = $db->prepare("INSERT OR IGNORE INTO upload_refs (post_id, stored_name) VALUES (?, ?)");

    $stmt = $db->prepare("SELECT stored_name FROM post_attachments WHERE post_id = ?");
    $stmt->execute([$post_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $insert->execute([$post_id, $name]);
    }

    $stmt = $db->prepare("SELECT content FROM posts WHERE id = ?");
    $stmt->execute([$post_id]);
    foreach (wiki_content_upload_names((string) $stmt->fetchColumn()) as $name) {
        $insert->execute([$post_id, $name]);
    }
}

/**
 * 첨부 목록을 교체하고, 목록에서 빠진 파일 이름을 돌려준다.
 */
function wiki_replace_attachments( PDO $db, $post_id, $attachments )
{
    $post_id = (int) $post_id;

    $stmt = $db->prepare("SELECT stored_name FROM post_attachments WHERE post_id = ?");
    $stmt->execute([$post_id]);
    $previous = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $db->prepare("DELETE FROM post_attachments WHERE post_id = ?");
    $stmt->execute([$post_id]);

    $insert = $db->prepare(
        "INSERT INTO post_attachments (post_id, stored_name, original_name, mime_type, size, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($attachments as $order => $file) {
        $insert->execute([
            $post_id, $file["storedName"], $file["originalName"], $file["mimeType"], $file["size"], $order,
        ]);
    }

    wiki_sync_upload_refs($db, $post_id);

    $kept = array_flip(array_column($attachments, "storedName"));
    return array_values(array_filter($previous, function ($name) use ($kept) {
        return !isset($kept[$name]);
    }));
}

function wiki_apply_homepage( PDO $db, $post_id, $value, $sort = null )
{
    $enabled = $value === true || $value === 1 || $value === "1" || $value === "true";

    if (!$enabled) {
        $stmt = $db->prepare("DELETE FROM homepage_posts WHERE post_id = ?");
        $stmt->execute([(int) $post_id]);
        return;
    }

    $order = is_numeric($sort) ? max(0, min(9999, (int) $sort)) : 0;
    $stmt = $db->prepare(
        "INSERT INTO homepage_posts (post_id, sort_order) VALUES (?, ?)
         ON CONFLICT(post_id) DO UPDATE SET sort_order = excluded.sort_order"
    );
    $stmt->execute([(int) $post_id, $order]);
}
