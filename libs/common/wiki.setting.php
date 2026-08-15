<?php

/**
 * 상단 메뉴. 글에 연결된 항목은 읽을 수 없는 사용자에게 감춘다.
 */
function wiki_top_menu_items( $user = null )
{
    $rows = wiki_db()->query(
        "SELECT top_menu_items.id, top_menu_items.label, top_menu_items.post_id,
                top_menu_items.url, top_menu_items.sort_order,
                posts.title AS post_title, posts.author_id, posts.visibility, posts.status,
                posts.category_id, posts.deleted_at
         FROM top_menu_items
         LEFT JOIN posts ON posts.id = top_menu_items.post_id
         ORDER BY top_menu_items.sort_order ASC, top_menu_items.id ASC"
    )->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        if ($row["post_id"] !== null && !wiki_can_read_post($row, $user)) {
            continue;
        }

        $item = [
            "id" => (int) $row["id"],
            "label" => (string) $row["label"],
        ];
        if ($row["post_id"] !== null) {
            $item["postId"] = (int) $row["post_id"];
            $item["postTitle"] = (string) ($row["post_title"] ?? "");
        } else {
            $item["url"] = (string) ($row["url"] ?? "");
        }
        $items[] = $item;
    }

    return $items;
}

function wiki_settings( $user = null )
{
    $values = [];
    foreach (wiki_db()->query("SELECT key, value FROM settings")->fetchAll() as $row) {
        $values[$row["key"]] = $row["value"];
    }

    $home_ids = array_map("intval", wiki_db()->query(
        "SELECT post_id FROM homepage_posts ORDER BY sort_order ASC"
    )->fetchAll(PDO::FETCH_COLUMN));

    return [
        "siteTitle" => $values["site_title"] ?? "Wikiman",
        "theme" => ($values["theme"] ?? "light") === "dark" ? "dark" : "light",
        "plantumlServer" => $values["plantuml_server"] ?? "https://www.plantuml.com/plantuml",
        "defaultEditor" => $values["default_editor"] ?? "ckeditor",
        "defaultEditorMobile" => $values["default_editor_mobile"] ?? "ckeditor",
        "favicon" => $values["favicon"] ?? "",
        "maxAttachmentMb" => (int) ($values["max_attachment_mb"] ?? 20),
        "categoryTreeExpand" => $values["category_tree_expand"] ?? "expanded",
        "categoryTreeSide" => $values["category_tree_side"] ?? "left",
        "fontScale" => (int) ($values["font_scale"] ?? 100),
        "topMenuVisible" => ($values["top_menu_visible"] ?? "1") === "1",
        "mobileQuickPostEnabled" => ($values["mobile_quick_post_enabled"] ?? "0") === "1",
        "quickPostPromoteSourceMode" => $values["quick_post_promote_source_mode"] ?? "ask",
        "quickPostPromoteEditor" => $values["quick_post_promote_editor"] ?? "ask",
        "linkPreviewCacheTtlDays" => (int) ($values["link_preview_cache_ttl_days"] ?? 10),
        "linkPreviewFailureTtlDays" => (int) ($values["link_preview_failure_ttl_days"] ?? 1),
        "homePostIds" => $home_ids,
        "hasHomepage" => count($home_ids) > 0,
        "topMenuItems" => wiki_top_menu_items($user),
    ];
}

function wiki_save_setting( $key, $value )
{
    $stmt = wiki_db()->prepare(
        "INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value"
    );
    $stmt->execute([$key, (string) $value]);
}

/**
 * 설정 입력 키 -> [저장 키, 검증 함수]
 */
function wiki_setting_schema()
{
    return [
        "siteTitle" => ["site_title", function ($value) {
            $value = trim((string) $value);
            if ($value === "" || mb_strlen($value) > 80) {
                wiki_abort(400, "사이트 제목은 1~80자로 입력하세요.");
            }
            return $value;
        }],
        "theme" => ["theme", function ($value) {
            return $value === "dark" ? "dark" : "light";
        }],
        "plantumlServer" => ["plantuml_server", function ($value) {
            return rtrim(trim((string) $value), "/");
        }],
        "defaultEditor" => ["default_editor", "wiki_normalize_editor"],
        "defaultEditorMobile" => ["default_editor_mobile", "wiki_normalize_editor"],
        "favicon" => ["favicon", function ($value) {
            return mb_substr(trim((string) $value), 0, 500);
        }],
        "maxAttachmentMb" => ["max_attachment_mb", function ($value) {
            return max(1, min(200, (int) $value));
        }],
        "categoryTreeExpand" => ["category_tree_expand", function ($value) {
            return in_array($value, ["expanded", "collapsed", "root"], true) ? $value : "expanded";
        }],
        "categoryTreeSide" => ["category_tree_side", function ($value) {
            return $value === "right" ? "right" : "left";
        }],
        "fontScale" => ["font_scale", function ($value) {
            return max(60, min(120, (int) $value));
        }],
        "topMenuVisible" => ["top_menu_visible", function ($value) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }],
        "mobileQuickPostEnabled" => ["mobile_quick_post_enabled", function ($value) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }],
        "quickPostPromoteSourceMode" => ["quick_post_promote_source_mode", function ($value) {
            return in_array($value, ["ask", "delete", "keep"], true) ? $value : "ask";
        }],
        "quickPostPromoteEditor" => ["quick_post_promote_editor", function ($value) {
            return $value === "ask" ? "ask" : wiki_normalize_editor($value);
        }],
        "linkPreviewCacheTtlDays" => ["link_preview_cache_ttl_days", function ($value) {
            return max(1, min(365, (int) $value));
        }],
        "linkPreviewFailureTtlDays" => ["link_preview_failure_ttl_days", function ($value) {
            return max(1, min(365, (int) $value));
        }],
    ];
}
