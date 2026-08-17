<?php

require __DIR__ . "/../libs/common/wiki.editor-convert.php";

function wiki_assert( $condition, $message )
{
    if ($condition) return;
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$html = wiki_convert_editor_content("메모\n\n![그림](/api/files/photo.png)", "tui", "ckeditor");
wiki_assert(str_contains($html, "<p>메모</p>"), "markdown paragraph becomes html");
wiki_assert(str_contains($html, '<img src="/api/files/photo.png" alt="그림">'), "markdown image becomes img");
wiki_assert(!str_contains($html, "![그림]"), "markdown image syntax is not left as text");

$blocks = json_decode(wiki_convert_editor_content("메모\n\n![](/api/files/photo.png)", "tui", "editorjs"), true)["blocks"];
wiki_assert(($blocks[0]["type"] ?? "") === "paragraph", "text paragraph is preserved");
wiki_assert(($blocks[1]["type"] ?? "") === "image", "markdown image becomes editorjs image");
wiki_assert(($blocks[1]["data"]["file"]["url"] ?? "") === "/api/files/photo.png", "image url is preserved");

$same = wiki_convert_editor_content("![그림](/api/files/a.png)", "tui", "markdown");
wiki_assert($same === "![그림](/api/files/a.png)", "same markdown kind is unchanged");

$text_blocks = json_decode(wiki_convert_editor_content("첫 문단\n\n둘째 문단", "textarea", "editorjs"), true)["blocks"];
wiki_assert(count($text_blocks) === 2, "plain text becomes two editorjs paragraphs");

fwrite(STDOUT, "ok\n");
