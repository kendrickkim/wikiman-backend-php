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
    foreach (wiki_db()->query("SELECT `key`, `value` FROM settings")->fetchAll() as $row) {
        $values[$row["key"]] = $row["value"];
    }

    $home_ids = array_map("intval", wiki_db()->query(
        "SELECT post_id FROM homepage_posts ORDER BY sort_order ASC"
    )->fetchAll(PDO::FETCH_COLUMN));

    return [
        "siteTitle" => $values["site_title"] ?? "Wikiman",
        "databaseDriver" => wiki_db_driver(),
        "backupSupported" => wiki_db_driver() === "sqlite",
        "siteLanguage" => in_array(($values["site_language"] ?? "ko-KR"), ["ko-KR", "en-US"], true)
            ? ($values["site_language"] ?? "ko-KR") : "ko-KR",
        "theme" => ($values["theme"] ?? "light") === "dark" ? "dark" : "light",
        "plantumlServer" => $values["plantuml_server"] ?? "https://www.plantuml.com/plantuml",
        "defaultEditor" => $values["default_editor"] ?? "ckeditor",
        "defaultEditorMobile" => $values["default_editor_mobile"] ?? "ckeditor",
        "favicon" => $values["favicon"] ?? "",
        "maxAttachmentMb" => (int) ($values["max_attachment_mb"] ?? 20),
        "categoryTreeExpand" => $values["category_tree_expand"] ?? "expanded",
        "categoryTreeSide" => $values["category_tree_side"] ?? "left",
        "rightMenuDefaultOpen" => ($values["right_menu_default_open"] ?? "1") === "1",
        "fontScale" => (int) ($values["font_scale"] ?? 100),
        "topMenuVisible" => ($values["top_menu_visible"] ?? "1") === "1",
        "mobileQuickPostEnabled" => ($values["mobile_quick_post_enabled"] ?? "0") === "1",
        "blogMode" => ($values["blog_mode"] ?? "0") === "1",
        "blogShowHomepage" => ($values["blog_show_homepage"] ?? "0") === "1",
        "blogPostsPerPage" => (int) ($values["blog_posts_per_page"] ?? 10),
        "codeLineNumbers" => ($values["code_line_numbers"] ?? "0") === "1",
        "quickPostEditor" => in_array(($values["quick_post_editor"] ?? "tui"), WIKI_EDITORS, true)
            ? ($values["quick_post_editor"] ?? "tui") : "tui",
        "quickPostPromoteSourceMode" => $values["quick_post_promote_source_mode"] ?? "ask",
        "quickPostPromoteEditor" => $values["quick_post_promote_editor"] ?? "ask",
        "linkPreviewCacheTtlDays" => (int) ($values["link_preview_cache_ttl_days"] ?? 10),
        "linkPreviewFailureTtlDays" => (int) ($values["link_preview_failure_ttl_days"] ?? 1),
        "thumbnailCacheDays" => (int) ($values["thumbnail_cache_days"] ?? 100),
        "homePostIds" => $home_ids,
        "hasHomepage" => count($home_ids) > 0,
        "topMenuItems" => wiki_top_menu_items($user),
    ];
}

function wiki_save_setting( $key, $value )
{
    $sql = wiki_db_driver() === "mysql"
        ? "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        : "INSERT INTO settings (`key`, `value`) VALUES (?, ?)
           ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`";
    $stmt = wiki_db()->prepare($sql);
    $stmt->execute([$key, (string) $value]);
}

function wiki_setting_boolean( $value, $error_code )
{
    if ($value === true || $value === false) {
        return $value ? 1 : 0;
    }
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ["1", "true", "yes", "on"], true)) return 1;
    if (in_array($normalized, ["0", "false", "no", "off"], true)) return 0;
    wiki_abort(400, $error_code);
}

function wiki_setting_integer( $value, $minimum, $maximum, $error_code )
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        wiki_abort(400, $error_code);
    }
    $number = (int) $value;
    if ($number < $minimum || $number > $maximum) {
        wiki_abort(400, $error_code);
    }
    return $number;
}

function wiki_setting_editor( $value, $error_code )
{
    $editor = trim((string) $value);
    if (!in_array($editor, WIKI_EDITORS, true)) {
        wiki_abort(400, $error_code);
    }
    return $editor;
}

function wiki_normalize_top_menu_url( $value )
{
    $url = trim((string) $value);
    if ($url === "") wiki_abort(400, "URL_REQUIRED");
    if (mb_strlen($url) > 500) wiki_abort(400, "URL_TOO_LONG", ["max" => 500]);
    if (preg_match('/[\s\x00-\x1F\x7F]/u', $url)) wiki_abort(400, "URL_WHITESPACE");

    if (str_starts_with($url, "/")) {
        if (str_starts_with($url, "//") || str_contains($url, "\\")) {
            wiki_abort(400, "INTERNAL_PATH_INVALID");
        }
        return $url;
    }

    if (!preg_match('#^https?://#i', $url)) $url = "https://" . $url;
    if (mb_strlen($url) > 500) wiki_abort(400, "URL_TOO_LONG", ["max" => 500]);
    if (!filter_var($url, FILTER_VALIDATE_URL)) wiki_abort(400, "URL_INVALID");
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ["http", "https"], true)) {
        wiki_abort(400, "EXTERNAL_URL_INVALID");
    }
    return $url;
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
                wiki_abort(400, "SITE_TITLE_LENGTH");
            }
            return $value;
        }],
        "siteLanguage" => ["site_language", function ($value) {
            $language = str_replace("_", "-", trim((string) $value));
            if ($language === "ko") $language = "ko-KR";
            if ($language === "en") $language = "en-US";
            if (!in_array($language, ["ko-KR", "en-US"], true)) {
                wiki_abort(400, "SITE_LANGUAGE_INVALID");
            }
            return $language;
        }],
        "theme" => ["theme", function ($value) {
            if (!in_array($value, ["light", "dark"], true)) {
                wiki_abort(400, "THEME_INVALID");
            }
            return $value;
        }],
        "plantumlServer" => ["plantuml_server", function ($value) {
            $url = rtrim(trim((string) $value), "/");
            if (!filter_var($url, FILTER_VALIDATE_URL)
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ["http", "https"], true)) {
                wiki_abort(400, "PLANTUML_URL_INVALID");
            }
            return $url;
        }],
        "defaultEditor" => ["default_editor", function ($value) {
            return wiki_setting_editor($value, "DEFAULT_EDITOR_INVALID");
        }],
        "defaultEditorMobile" => ["default_editor_mobile", function ($value) {
            return wiki_setting_editor($value, "DEFAULT_EDITOR_MOBILE_INVALID");
        }],
        "favicon" => ["favicon", function ($value) {
            $favicon = trim((string) $value);
            if ($favicon === "") return "";
            if (!preg_match('#^/api/files/([^/?\#]+)$#', $favicon, $matches)
                || basename($matches[1]) !== $matches[1]) {
                wiki_abort(400, "FAVICON_UPLOAD_ONLY");
            }
            return $favicon;
        }],
        "maxAttachmentMb" => ["max_attachment_mb", function ($value) {
            return wiki_setting_integer($value, 1, 200, "MAX_ATTACHMENT_MB_INVALID");
        }],
        "categoryTreeExpand" => ["category_tree_expand", function ($value) {
            if (!in_array($value, ["expanded", "collapsed", "root"], true)) {
                wiki_abort(400, "CATEGORY_TREE_EXPAND_INVALID");
            }
            return $value;
        }],
        "categoryTreeSide" => ["category_tree_side", function ($value) {
            if (!in_array($value, ["left", "right"], true)) {
                wiki_abort(400, "CATEGORY_TREE_SIDE_INVALID");
            }
            return $value;
        }],
        "rightMenuDefaultOpen" => ["right_menu_default_open", function ($value) {
            return wiki_setting_boolean($value, "RIGHT_MENU_DEFAULT_OPEN_INVALID");
        }],
        "fontScale" => ["font_scale", function ($value) {
            return wiki_setting_integer($value, 60, 120, "FONT_SCALE_INVALID");
        }],
        "topMenuVisible" => ["top_menu_visible", function ($value) {
            return wiki_setting_boolean($value, "TOP_MENU_VISIBLE_INVALID");
        }],
        "mobileQuickPostEnabled" => ["mobile_quick_post_enabled", function ($value) {
            return wiki_setting_boolean($value, "MOBILE_QUICK_POST_INVALID");
        }],
        "blogMode" => ["blog_mode", function ($value) {
            return wiki_setting_boolean($value, "BLOG_MODE_INVALID");
        }],
        "blogShowHomepage" => ["blog_show_homepage", function ($value) {
            return wiki_setting_boolean($value, "BLOG_SHOW_HOMEPAGE_INVALID");
        }],
        "blogPostsPerPage" => ["blog_posts_per_page", function ($value) {
            return wiki_setting_integer($value, 1, 100, "BLOG_POSTS_PER_PAGE_INVALID");
        }],
        "codeLineNumbers" => ["code_line_numbers", function ($value) {
            return wiki_setting_boolean($value, "CODE_LINE_NUMBERS_INVALID");
        }],
        "quickPostEditor" => ["quick_post_editor", function ($value) {
            return wiki_setting_editor($value, "QUICK_POST_EDITOR_INVALID");
        }],
        "quickPostPromoteSourceMode" => ["quick_post_promote_source_mode", function ($value) {
            if (!in_array($value, ["ask", "delete", "keep"], true)) {
                wiki_abort(400, "QUICK_POST_PROMOTE_SOURCE_INVALID");
            }
            return $value;
        }],
        "quickPostPromoteEditor" => ["quick_post_promote_editor", function ($value) {
            return $value === "ask" ? "ask" : wiki_setting_editor($value, "QUICK_POST_PROMOTE_EDITOR_INVALID");
        }],
        "linkPreviewCacheTtlDays" => ["link_preview_cache_ttl_days", function ($value) {
            return wiki_setting_integer($value, 1, 365, "LINK_PREVIEW_CACHE_TTL_INVALID");
        }],
        "linkPreviewFailureTtlDays" => ["link_preview_failure_ttl_days", function ($value) {
            return wiki_setting_integer($value, 1, 365, "LINK_PREVIEW_FAILURE_TTL_INVALID");
        }],
        "thumbnailCacheDays" => ["thumbnail_cache_days", function ($value) {
            return wiki_setting_integer($value, 1, 365, "THUMBNAIL_CACHE_DAYS_INVALID");
        }],
    ];
}
