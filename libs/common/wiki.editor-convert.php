<?php

const WIKI_EMPTY_EDITORJS = '{"blocks":[]}';

function wiki_editor_kind( $editor_type )
{
    switch ((string) $editor_type) {
        case "textarea":
            return "text";
        case "tui":
        case "markdown":
            return "markdown";
        case "editorjs":
            return "editorjs";
        default:
            return "html";
    }
}

function wiki_convert_escape_html( $text )
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function wiki_convert_decode_entities( $text )
{
    return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, "UTF-8");
}

function wiki_convert_text_to_html( $text )
{
    $value = (string) $text;
    if (trim($value) === "") return "";
    $parts = preg_split('/\n{2,}/', $value) ?: [];
    $out = [];
    foreach ($parts as $para) {
        $out[] = "<p>" . nl2br(wiki_convert_escape_html($para), false) . "</p>";
    }
    return implode("\n", $out);
}

function wiki_convert_html_to_text( $html )
{
    $value = (string) $html;
    if (trim($value) === "") return "";
    $value = preg_replace('/<\s*(br|hr)\s*\/?>/i', "\n", $value) ?? "";
    $value = preg_replace('/<\/\s*(p|div|li|tr|h[1-6]|pre|blockquote|figure|figcaption)\s*>/i', "\n", $value) ?? "";
    $value = preg_replace('/<\s*li[^>]*>/i', "- ", $value) ?? "";
    $value = preg_replace('/<[^>]+>/', "", $value) ?? "";
    $value = wiki_convert_decode_entities($value);
    $value = str_replace("\r\n", "\n", $value);
    $value = preg_replace('/[ \t]+\n/', "\n", $value) ?? "";
    $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? "";
    return trim($value);
}

function wiki_convert_parse_blocks( $content )
{
    $parsed = json_decode((string) $content, true);
    if (is_array($parsed) && array_is_list($parsed)) return $parsed;
    if (is_array($parsed) && isset($parsed["blocks"]) && is_array($parsed["blocks"])) {
        return $parsed["blocks"];
    }
    return [];
}

function wiki_convert_list_item_content( $item )
{
    if (is_string($item)) return $item;
    if (!is_array($item)) return "";
    return (string) ($item["content"] ?? $item["text"] ?? "");
}

function wiki_convert_list_to_html( $items, $style )
{
    if (!is_array($items) || !$items) return "";
    $tag = $style === "ordered" ? "ol" : "ul";
    $body = "";
    foreach ($items as $item) {
        $children = is_array($item) ? wiki_convert_list_to_html($item["items"] ?? [], $style) : "";
        $body .= "<li>" . wiki_convert_list_item_content($item) . $children . "</li>";
    }
    return "<{$tag}>{$body}</{$tag}>";
}

function wiki_convert_editorjs_to_html( $content )
{
    $parts = [];
    foreach (wiki_convert_parse_blocks($content) as $block) {
        $data = is_array($block["data"] ?? null) ? $block["data"] : [];
        switch ($block["type"] ?? "") {
            case "header":
                $level = min(max((int) ($data["level"] ?? 2), 1), 6);
                $parts[] = "<h{$level}>" . ($data["text"] ?? "") . "</h{$level}>";
                break;
            case "list":
                $parts[] = wiki_convert_list_to_html($data["items"] ?? [], $data["style"] ?? "unordered");
                break;
            case "code":
                $parts[] = "<pre><code>" . wiki_convert_escape_html($data["code"] ?? "") . "</code></pre>";
                break;
            case "image":
                $url = (string) ($data["file"]["url"] ?? $data["url"] ?? "");
                if ($url === "") {
                    if (!empty($data["caption"])) $parts[] = "<p>" . $data["caption"] . "</p>";
                    break;
                }
                $caption = !empty($data["caption"])
                    ? "<figcaption>" . wiki_convert_escape_html($data["caption"]) . "</figcaption>"
                    : "";
                $parts[] = "<figure><img src=\"" . wiki_convert_escape_html($url)
                    . "\" alt=\"" . wiki_convert_escape_html($data["caption"] ?? "") . "\">{$caption}</figure>";
                break;
            case "paragraph":
                $parts[] = "<p>" . ($data["text"] ?? "") . "</p>";
                break;
            default:
                $text = (string) ($data["text"] ?? $data["caption"] ?? $data["code"] ?? "");
                if ($text !== "") $parts[] = "<p>{$text}</p>";
        }
    }
    return implode("\n", array_filter($parts, function ($part) {
        return $part !== "";
    }));
}

function wiki_convert_keep_inline_only( $html )
{
    return trim((string) preg_replace_callback('/<\/?([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/', function ($match) {
        $name = strtolower($match[1]);
        $keep = ["a", "b", "strong", "i", "em", "u", "s", "del", "mark", "code", "br"];
        if (!in_array($name, $keep, true)) return "";
        if ($name === "br") return "<br>";
        if ($name === "a") return str_starts_with($match[0], "</") ? "</a>" : $match[0];
        return str_starts_with($match[0], "</") ? "</{$name}>" : "<{$name}>";
    }, (string) $html) ?? "");
}

function wiki_convert_paragraph_block( $text )
{
    $value = trim((string) $text);
    return $value === "" ? null : ["type" => "paragraph", "data" => ["text" => $value]];
}

function wiki_convert_attr_value( $tag, $name )
{
    if (!preg_match('/\b' . preg_quote($name, "/") . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', (string) $tag, $match)) {
        return "";
    }
    return (string) ($match[1] ?? $match[2] ?? $match[3] ?? "");
}

function wiki_convert_image_block_from_html( $html )
{
    $src = wiki_convert_attr_value($html, "src");
    if ($src === "") return null;
    return [
        "type" => "image",
        "data" => [
            "file" => ["url" => $src],
            "caption" => wiki_convert_attr_value($html, "alt"),
        ],
    ];
}

function wiki_convert_push_html_element( &$blocks, $html )
{
    $value = trim((string) $html);
    if ($value === "") return;
    if (preg_match('/<img\b/i', $value)) {
        $image = wiki_convert_image_block_from_html($value);
        if ($image) $blocks[] = $image;
        return;
    }
    if (preg_match('/^<h([1-6])\b[^>]*>([\s\S]*)<\/h\1>$/i', $value, $heading)) {
        $text = wiki_convert_keep_inline_only($heading[2]);
        if ($text !== "") {
            $blocks[] = ["type" => "header", "data" => ["text" => $text, "level" => (int) $heading[1]]];
        }
        return;
    }
    if (preg_match('/^<pre\b[^>]*>([\s\S]*)<\/pre>$/i', $value, $pre)) {
        $code = wiki_convert_html_to_text($pre[1]);
        if (trim($code) !== "") $blocks[] = ["type" => "code", "data" => ["code" => $code]];
        return;
    }
    if (preg_match('/^<(ul|ol)\b[^>]*>([\s\S]*)<\/\1>$/i', $value, $list)) {
        if (preg_match_all('/<li\b[^>]*>([\s\S]*?)<\/li>/i', $list[2], $items)) {
            foreach ($items[1] as $item) {
                $block = wiki_convert_paragraph_block("- " . wiki_convert_keep_inline_only($item));
                if ($block) $blocks[] = $block;
            }
        }
        return;
    }
    $inner = preg_replace('/^<[a-zA-Z][a-zA-Z0-9]*\b[^>]*>/', "", $value) ?? $value;
    $inner = preg_replace('/<\/[a-zA-Z][a-zA-Z0-9]*>$/', "", $inner) ?? $inner;
    $block = wiki_convert_paragraph_block(wiki_convert_keep_inline_only($inner ?: $value));
    if ($block) $blocks[] = $block;
}

function wiki_convert_html_to_editorjs( $html )
{
    $value = (string) $html;
    if (trim($value) === "") return WIKI_EMPTY_EDITORJS;
    $blocks = [];
    if (preg_match_all('/<figure\b[\s\S]*?<\/figure>|<img\b[^>]*>|<(h[1-6]|p|pre|ul|ol|blockquote|div|section)\b[^>]*>[\s\S]*?<\/\1>/i', $value, $matches, PREG_OFFSET_CAPTURE)) {
        $last = 0;
        foreach ($matches[0] as $match) {
            $before = trim(substr($value, $last, $match[1] - $last));
            if ($before !== "") {
                $fallback = wiki_convert_paragraph_block(nl2br(wiki_convert_escape_html(wiki_convert_html_to_text($before)), false));
                if ($fallback) $blocks[] = $fallback;
            }
            wiki_convert_push_html_element($blocks, $match[0]);
            $last = $match[1] + strlen($match[0]);
        }
        $after = trim(substr($value, $last));
        if ($after !== "") {
            $fallback = wiki_convert_paragraph_block(nl2br(wiki_convert_escape_html(wiki_convert_html_to_text($after)), false));
            if ($fallback) $blocks[] = $fallback;
        }
    }
    if (!$blocks) {
        $fallback = wiki_convert_paragraph_block(nl2br(wiki_convert_escape_html(wiki_convert_html_to_text($value)), false));
        return json_encode(["blocks" => $fallback ? [$fallback] : []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return json_encode(["blocks" => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wiki_convert_markdown_to_html( $source )
{
    $placeholders = [];
    $index = 0;
    $text = preg_replace_callback('/```[\s\S]*?```/', function ($match) use (&$placeholders, &$index) {
        $key = "CODE" . $index;
        $index += 1;
        $code = preg_replace('/^```[^\n]*\n?/', "", $match[0]) ?? $match[0];
        $code = preg_replace('/```$/', "", $code) ?? $code;
        $placeholders[$key] = "<pre><code>" . wiki_convert_escape_html($code) . "</code></pre>";
        return "__WIKI_{$key}__";
    }, (string) $source) ?? (string) $source;

    $text = preg_replace_callback('/!\[([^\]]*)\]\(\s*(?:<([^>]+)>|([^)\s]+))\s*(?:"[^"]*")?\)/', function ($match) use (&$placeholders, &$index) {
        $key = "IMG" . $index;
        $index += 1;
        $url = (string) ($match[2] !== "" ? $match[2] : ($match[3] ?? ""));
        $placeholders[$key] = "<img src=\"" . wiki_convert_escape_html($url)
            . "\" alt=\"" . wiki_convert_escape_html($match[1]) . "\">";
        return "__WIKI_{$key}__";
    }, $text) ?? $text;

    $paragraphs = array_values(array_filter(array_map("trim", preg_split('/\n{2,}/', $text) ?: []), function ($para) {
        return $para !== "";
    }));

    $parts = [];
    foreach ($paragraphs as $para) {
        $inline = function ($value) use ($placeholders) {
            $escaped = nl2br(wiki_convert_escape_html($value), false);
            return preg_replace_callback('/__WIKI_([A-Z]+\d+)__/', function ($match) use ($placeholders) {
                return $placeholders[$match[1]] ?? "";
            }, $escaped) ?? $escaped;
        };
        if (preg_match('/^__WIKI_(CODE\d+)__$/', $para, $code_only)) {
            $parts[] = $placeholders[$code_only[1]] ?? "";
            continue;
        }
        if (preg_match('/^(#{1,6})\s+([\s\S]+)$/', $para, $heading)) {
            $level = strlen($heading[1]);
            $parts[] = "<h{$level}>" . $inline($heading[2]) . "</h{$level}>";
            continue;
        }
        $parts[] = "<p>" . $inline($para) . "</p>";
    }
    return implode("\n", $parts);
}

function wiki_convert_markdown_to_editorjs( $source )
{
    $blocks = [];
    $protected = preg_replace_callback('/```[\s\S]*?```/', function ($match) use (&$blocks) {
        $code = preg_replace('/^```[^\n]*\n?/', "", $match[0]) ?? $match[0];
        $code = preg_replace('/```$/', "", $code) ?? $code;
        if (trim($code) !== "") $blocks[] = ["type" => "code", "data" => ["code" => $code]];
        return "\n\n";
    }, (string) $source) ?? (string) $source;

    $image_re = '/!\[([^\]]*)\]\(\s*(?:<([^>]+)>|([^)\s]+))\s*(?:"[^"]*")?\)/';
    foreach (array_values(array_filter(array_map("trim", preg_split('/\n{2,}/', $protected) ?: []))) as $para) {
        if ($para === "") continue;
        preg_match_all($image_re, $para, $images, PREG_SET_ORDER);
        $without = trim((string) (preg_replace($image_re, "", $para) ?? ""));
        if ($images && $without === "") {
            foreach ($images as $image) {
                $url = (string) (($image[2] ?? "") !== "" ? $image[2] : ($image[3] ?? ""));
                if ($url !== "") {
                    $blocks[] = [
                        "type" => "image",
                        "data" => ["file" => ["url" => $url], "caption" => $image[1] ?? ""],
                    ];
                }
            }
            continue;
        }
        if ($images) {
            $nested = json_decode(wiki_convert_html_to_editorjs(wiki_convert_markdown_to_html($para)), true);
            foreach (($nested["blocks"] ?? []) as $block) $blocks[] = $block;
            continue;
        }
        if (preg_match('/^(#{1,6})\s+([\s\S]+)$/', $para, $heading)) {
            $blocks[] = [
                "type" => "header",
                "data" => ["text" => wiki_convert_escape_html($heading[2]), "level" => strlen($heading[1])],
            ];
            continue;
        }
        $block = wiki_convert_paragraph_block(nl2br(wiki_convert_escape_html($para), false));
        if ($block) $blocks[] = $block;
    }
    return json_encode(["blocks" => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function wiki_convert_editor_content( $content, $from_type, $to_type )
{
    $from = wiki_editor_kind($from_type);
    $to = wiki_editor_kind($to_type);
    $source = (string) $content;
    if (trim($source) === "" || ($from === "editorjs" && !wiki_convert_parse_blocks($source))) {
        return $to === "editorjs" ? WIKI_EMPTY_EDITORJS : "";
    }
    if ($from === $to) return $source;

    if ($to === "text") {
        if ($from === "markdown") return $source;
        if ($from === "editorjs") return wiki_convert_html_to_text(wiki_convert_editorjs_to_html($source));
        return wiki_convert_html_to_text($source);
    }

    if ($to === "markdown") {
        if ($from === "text") return $source;
        if ($from === "editorjs") return wiki_convert_editorjs_to_html($source);
        return $source;
    }

    if ($to === "editorjs") {
        if ($from === "text") return wiki_convert_html_to_editorjs(wiki_convert_text_to_html($source));
        if ($from === "markdown") return wiki_convert_markdown_to_editorjs($source);
        return wiki_convert_html_to_editorjs($source);
    }

    if ($from === "text") return wiki_convert_text_to_html($source);
    if ($from === "markdown") return wiki_convert_markdown_to_html($source);
    if ($from === "editorjs") return wiki_convert_editorjs_to_html($source);
    return $source;
}
