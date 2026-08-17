<?php

const WIKI_SOCIAL_DEFAULT_ICON = "/icons/apple-touch-icon.png";

function wiki_social_absolute_url( $origin, $value )
{
    $raw = trim((string) $value);
    if ($raw === "" || preg_match('/^(?:data|blob):/i', $raw)) return "";
    if (preg_match('#^https?://#i', $raw)) {
        $parts = parse_url($raw);
        return is_array($parts) && !empty($parts["host"])
            && in_array(strtolower((string) ($parts["scheme"] ?? "")), ["http", "https"], true)
            && !preg_match('/[\x00-\x20\x7f]/', $raw) ? $raw : "";
    }
    if (str_starts_with($raw, "//")) {
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME)) === "https" ? "https:" : "http:";
        $absolute = $scheme . $raw;
        $parts = parse_url($absolute);
        return is_array($parts) && !empty($parts["host"])
            && !preg_match('/[\x00-\x20\x7f]/', $absolute) ? $absolute : "";
    }
    return rtrim((string) $origin, "/") . "/" . ltrim($raw, "/");
}

function wiki_social_thumbnail_url( $origin, $value )
{
    $raw = trim((string) $value);
    $parts = parse_url($raw);
    if ($raw === "" || !is_array($parts)) return $raw;
    $path = (string) ($parts["path"] ?? "");
    if (!preg_match('#^/api/(?:files/[^/]+|posts/\d+/files/[^/]+)$#', $path)) {
        return $raw;
    }

    if (isset($parts["host"])) {
        $origin_parts = parse_url($origin);
        if (!is_array($origin_parts)) return $raw;
        $origin_scheme = strtolower((string) ($origin_parts["scheme"] ?? "http"));
        $value_scheme = strtolower((string) ($parts["scheme"] ?? $origin_scheme));
        $origin_port = (int) ($origin_parts["port"] ?? ($origin_scheme === "https" ? 443 : 80));
        $value_port = (int) ($parts["port"] ?? ($value_scheme === "https" ? 443 : 80));
        $same_host = strtolower((string) ($parts["host"] ?? "")) === strtolower((string) ($origin_parts["host"] ?? ""))
            && $value_scheme === $origin_scheme
            && $value_port === $origin_port;
        if (!$same_host) return $raw;
    }

    if (preg_match('/([?&])thumb=[^&#]*/i', $raw)) {
        return preg_replace('/([?&])thumb=[^&#]*/i', '$1thumb=1', $raw, 1) ?? $raw;
    }
    $fragment_at = strpos($raw, "#");
    $base = $fragment_at === false ? $raw : substr($raw, 0, $fragment_at);
    $fragment = $fragment_at === false ? "" : substr($raw, $fragment_at);
    return $base . (str_contains($base, "?") ? "&" : "?") . "thumb=1" . $fragment;
}

function wiki_social_public_origin()
{
    $configured = rtrim(trim((string) getenv("PUBLIC_URL")), "/");
    if ($configured !== "" && filter_var($configured, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($configured, PHP_URL_SCHEME)), ["http", "https"], true)) {
        $scheme = (string) parse_url($configured, PHP_URL_SCHEME);
        $host = (string) parse_url($configured, PHP_URL_HOST);
        if (str_contains($host, ":") && !str_starts_with($host, "[")) $host = "[" . $host . "]";
        $port = (int) (parse_url($configured, PHP_URL_PORT) ?: 0);
        $port_suffix = $port && !(($scheme === "https" && $port === 443) || ($scheme === "http" && $port === 80))
            ? ":" . $port : "";
        return $scheme . "://" . $host . $port_suffix;
    }

    $forwarded_proto = trim(explode(",", (string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ""))[0]);
    $scheme = strtolower($forwarded_proto) === "https"
        || (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    $host = trim(explode(",", (string) ($_SERVER["HTTP_X_FORWARDED_HOST"]
        ?? $_SERVER["HTTP_HOST"] ?? "localhost"))[0]);
    if (!preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::\d{1,5})?$/i', $host)) $host = "localhost";
    return $scheme . "://" . $host;
}

function wiki_social_description( $content, $max_length = 200 )
{
    $text = (string) $content;
    $text = preg_replace('/```[\s\S]*?```/u', " ", $text) ?? "";
    $text = preg_replace('/<[^>]+>/u', " ", $text) ?? "";
    $text = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', " ", $text) ?? "";
    $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text) ?? "";
    $text = preg_replace('#https?://[^\s]+#u', " ", $text) ?? "";
    $text = preg_replace('/[#>*_~`|\[\]()-]+/u', " ", $text) ?? "";
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, "UTF-8");
    $text = trim(preg_replace('/\s+/u', " ", $text) ?? "");
    if ($text === "") return "";
    if (mb_strlen($text) <= $max_length) return $text;
    return rtrim(mb_substr($text, 0, max(1, $max_length - 1))) . "…";
}

function wiki_social_html_image( $content )
{
    preg_match('/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', (string) $content, $match);
    return (string) ($match[1] ?? $match[2] ?? $match[3] ?? "");
}

function wiki_social_markdown_image( $content )
{
    preg_match('/!\[[^\]]*\]\(\s*(?:<([^>]+)>|([^\s)]+))/u', (string) $content, $match);
    return (string) ($match[1] ?? $match[2] ?? "");
}

function wiki_social_editorjs_image( $content )
{
    $document = json_decode((string) $content, true);
    if (!is_array($document)) return "";
    foreach (($document["blocks"] ?? []) as $block) {
        if (($block["type"] ?? "") === "image" && !empty($block["data"]["file"]["url"])) {
            return (string) $block["data"]["file"]["url"];
        }
    }
    return "";
}

function wiki_social_first_image( $post, $origin )
{
    $content = (string) ($post["content"] ?? "");
    $image = ($post["editor_type"] ?? "") === "editorjs" ? wiki_social_editorjs_image($content) : "";
    if ($image === "") $image = wiki_social_html_image($content);
    if ($image === "" && in_array(($post["editor_type"] ?? ""), ["markdown", "tui"], true)) {
        $image = wiki_social_markdown_image($content);
    }
    if ($image === "" && !empty($post["id"])) {
        $stmt = wiki_db()->prepare(
            "SELECT stored_name FROM post_attachments
             WHERE post_id = ? AND mime_type LIKE 'image/%'
             ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        $stmt->execute([(int) $post["id"]]);
        $name = $stmt->fetchColumn();
        if ($name !== false) {
            $image = "/api/posts/" . (int) $post["id"] . "/files/" . rawurlencode((string) $name);
        }
    }
    $image = wiki_social_thumbnail_url($origin, $image ?: WIKI_SOCIAL_DEFAULT_ICON);
    $resolved = wiki_social_absolute_url($origin, $image);
    return $resolved !== "" ? $resolved : wiki_social_absolute_url($origin, WIKI_SOCIAL_DEFAULT_ICON);
}

function wiki_social_site_settings()
{
    $values = [];
    $stmt = wiki_db()->query(
        "SELECT `key`, `value` FROM settings WHERE `key` IN ('site_title', 'site_language')"
    );
    foreach ($stmt->fetchAll() as $row) $values[(string) $row["key"]] = (string) $row["value"];
    $language = ($values["site_language"] ?? "ko-KR") === "en-US" ? "en-US" : "ko-KR";
    return [
        "title" => trim((string) ($values["site_title"] ?? "Wikiman")) ?: "Wikiman",
        "lang" => $language,
        "locale" => $language === "en-US" ? "en_US" : "ko_KR",
    ];
}

function wiki_social_meta_for_path( $pathname, $origin )
{
    $path = (string) $pathname ?: "/";
    $settings = wiki_social_site_settings();
    $fallback = [
        "title" => $settings["title"],
        "description" => $settings["title"],
        "image" => wiki_social_absolute_url($origin, WIKI_SOCIAL_DEFAULT_ICON),
        "url" => wiki_social_absolute_url($origin, $path),
        "type" => "website",
        "siteName" => $settings["title"],
        "locale" => $settings["locale"],
        "lang" => $settings["lang"],
    ];

    if (!preg_match('#^/posts/(\d+)/?$#', $path, $match)) return $fallback;
    $stmt = wiki_db()->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->execute([(int) $match[1]]);
    $post = $stmt->fetch();
    if (!wiki_can_read_post($post, null)) return $fallback;

    $fallback["title"] = trim((string) ($post["title"] ?? "")) ?: $fallback["siteName"];
    $fallback["description"] = wiki_social_description($post["content"] ?? "");
    $fallback["image"] = wiki_social_first_image($post, $origin);
    $fallback["type"] = "article";
    $fallback["publishedTime"] = (string) ($post["created_at"] ?? "");
    $fallback["modifiedTime"] = (string) ($post["updated_at"] ?? "");
    return $fallback;
}

function wiki_social_escape( $value )
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, "UTF-8");
}

function wiki_social_meta_tag( $attribute, $key, $value )
{
    if ((string) $value === "") return "";
    return '<meta ' . $attribute . '="' . wiki_social_escape($key)
        . '" content="' . wiki_social_escape($value) . '">';
}

function wiki_social_render( $meta )
{
    $tags = [
        "<title>" . wiki_social_escape($meta["title"] ?? "") . "</title>",
        wiki_social_meta_tag("name", "description", $meta["description"] ?? ""),
        wiki_social_meta_tag("property", "og:title", $meta["title"] ?? ""),
        wiki_social_meta_tag("property", "og:description", $meta["description"] ?? ""),
        wiki_social_meta_tag("property", "og:type", $meta["type"] ?? ""),
        wiki_social_meta_tag("property", "og:url", $meta["url"] ?? ""),
        wiki_social_meta_tag("property", "og:image", $meta["image"] ?? ""),
        wiki_social_meta_tag("property", "og:site_name", $meta["siteName"] ?? ""),
        wiki_social_meta_tag("property", "og:locale", $meta["locale"] ?? ""),
        wiki_social_meta_tag("name", "twitter:card", "summary_large_image"),
        wiki_social_meta_tag("name", "twitter:title", $meta["title"] ?? ""),
        wiki_social_meta_tag("name", "twitter:description", $meta["description"] ?? ""),
        wiki_social_meta_tag("name", "twitter:image", $meta["image"] ?? ""),
        wiki_social_meta_tag("property", "article:published_time", $meta["publishedTime"] ?? ""),
        wiki_social_meta_tag("property", "article:modified_time", $meta["modifiedTime"] ?? ""),
        '<link rel="canonical" href="' . wiki_social_escape($meta["url"] ?? "") . '">',
    ];
    $tags = array_values(array_filter($tags, function ($tag) { return $tag !== ""; }));
    return "<!-- wikiman:meta:start -->\n    " . implode("\n    ", $tags)
        . "\n    <!-- wikiman:meta:end -->";
}

function wiki_social_strip_stale_meta( $head )
{
    $patterns = [
        '/<title\b[^>]*>[\s\S]*?<\/title>/i',
        '/<meta\b[^>]*\bname\s*=\s*(?:"description"|\'description\'|description(?=[\s\/>]))[^>]*>/i',
        '/<meta\b[^>]*\b(?:property|name)\s*=\s*(?:"(?:og|twitter|article):[^"]*"|\'(?:og|twitter|article):[^\']*\'|(?:og|twitter|article):[^\s\/>]*)[^>]*>/i',
        '/<link\b[^>]*\brel\s*=\s*(?:"canonical"|\'canonical\'|canonical(?=[\s\/>]))[^>]*>/i',
    ];
    return preg_replace($patterns, "", (string) $head) ?? (string) $head;
}

function wiki_social_inject( $html, $meta )
{
    $rendered = wiki_social_render($meta);
    $source = preg_replace_callback('/<html\b([^>]*)>/i', function ($match) use ($meta) {
        $attributes = $match[1];
        $lang = wiki_social_escape($meta["lang"] ?? "");
        if ($lang === "") return $match[0];
        if (preg_match('/\blang\s*=/i', $attributes)) {
            $attributes = preg_replace('/\blang\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', 'lang="' . $lang . '"', $attributes);
        } else {
            $attributes .= ' lang="' . $lang . '"';
        }
        return "<html" . $attributes . ">";
    }, (string) $html) ?? (string) $html;

    $head_end = stripos($source, "</head>");
    $head = $head_end === false ? $source : substr($source, 0, $head_end);
    $tail = $head_end === false ? "" : substr($source, $head_end);
    $marker = '/<!--\s*wikiman:meta:start\s*-->[\s\S]*?<!--\s*wikiman:meta:end\s*-->/i';
    $slot = "<!--wikiman:meta:slot-->";
    $with_slot = preg_match($marker, $head) ? preg_replace($marker, $slot, $head, 1) : $head;
    $cleaned = wiki_social_strip_stale_meta($with_slot);

    if (str_contains($cleaned, $slot)) return str_replace($slot, $rendered, $cleaned) . $tail;
    if ($head_end === false) return $cleaned . "\n" . $rendered;
    return $cleaned . "    " . $rendered . "\n  " . $tail;
}
