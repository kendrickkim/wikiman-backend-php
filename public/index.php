<?php

$app_dir = rtrim((string) (getenv("WIKIMAN_APP_DIR") ?: dirname(__DIR__)), "/");
if (!is_file($app_dir . "/install.php")) {
    http_response_code(500);
    header("Content-Type: text/plain; charset=utf-8");
    echo "WIKIMAN_APP_DIR이 올바르지 않습니다.";
    exit;
}

require_once($app_dir . "/libs/common/wiki.response.php");
require_once($app_dir . "/libs/common/wiki.config.php");
require_once($app_dir . "/libs/common/wiki.db.php");
require_once($app_dir . "/libs/common/wiki.category.php");
require_once($app_dir . "/libs/common/wiki.post.php");
require_once($app_dir . "/libs/common/wiki.social-meta.php");
require_once($app_dir . "/libs/installer/wiki.installer.php");

$reason = null;
if (!wiki_installer_is_healthy($reason)) {
    @unlink(__DIR__ . "/.wikiman-installed");
    require($app_dir . "/install.php");
    exit;
}
if (!is_file(__DIR__ . "/.wikiman-installed")) {
    @file_put_contents(__DIR__ . "/.wikiman-installed", gmdate("c") . "\n", LOCK_EX);
    @chmod(__DIR__ . "/.wikiman-installed", 0640);
}

$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
if (!in_array($method, ["GET", "HEAD"], true)) {
    http_response_code(405);
    header("Allow: GET, HEAD");
    exit;
}

$frontend_dir = rtrim((string) (getenv("WIKIMAN_FRONTEND_DIR") ?: __DIR__), "/");
$request_path = rawurldecode((string) (parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/"));
if (in_array($request_path, ["/index.php", "/index.html"], true)) {
    header("Location: /", true, 301);
    exit;
}
$relative = ltrim($request_path, "/");
$root = realpath($frontend_dir);
$candidate = realpath($frontend_dir . "/" . $relative);
$segments = array_filter(explode("/", str_replace("\\", "/", $relative)), "strlen");
$hidden_path = array_filter($segments, function ($part) {
    return str_starts_with($part, ".");
});
$blocked_extension = in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), [
    "php", "phtml", "phar", "inc",
], true);

if ($relative !== "" && $candidate && $root
    && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
    && is_file($candidate) && !$hidden_path && !$blocked_extension
    && basename($candidate) !== "index.html") {
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $mime_types = [
        "js" => "application/javascript; charset=utf-8",
        "mjs" => "application/javascript; charset=utf-8",
        "css" => "text/css; charset=utf-8",
        "json" => "application/json; charset=utf-8",
        "webmanifest" => "application/manifest+json; charset=utf-8",
        "svg" => "image/svg+xml",
        "png" => "image/png", "jpg" => "image/jpeg", "jpeg" => "image/jpeg",
        "gif" => "image/gif", "webp" => "image/webp", "ico" => "image/x-icon",
        "woff" => "font/woff", "woff2" => "font/woff2", "ttf" => "font/ttf",
        "wasm" => "application/wasm", "xml" => "application/xml; charset=utf-8",
        "txt" => "text/plain; charset=utf-8",
    ];
    $mime = $mime_types[$extension]
        ?? ((new finfo(FILEINFO_MIME_TYPE))->file($candidate) ?: "application/octet-stream");
    $basename = basename($candidate);
    header("Content-Type: " . $mime);
    header("X-Content-Type-Options: nosniff");
    header("Content-Length: " . filesize($candidate));
    $hashed_asset = preg_match('/(?:^|[.-])[a-f0-9]{8,}(?:[.-]|$)/i', $basename) === 1;
    header("Cache-Control: " . ($hashed_asset
        ? "public, max-age=31536000, immutable"
        : "no-cache"));
    if ($method !== "HEAD") readfile($candidate);
    exit;
}

$index = $frontend_dir . "/index.html";
if (!is_file($index)) {
    http_response_code(503);
    header("Content-Type: text/plain; charset=utf-8");
    echo "설치는 완료되었지만 프론트엔드 index.html이 없습니다.";
    exit;
}

try {
    $html = (string) file_get_contents($index);
    $meta = wiki_social_meta_for_path($request_path, wiki_social_public_origin());
    $html = wiki_social_inject($html, $meta);
} catch ( Throwable $error ) {
    error_log("wikiman: social meta injection failed - " . $error->getMessage());
    $html = (string) file_get_contents($index);
}

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-cache");
header("Content-Length: " . strlen($html));
if ($method !== "HEAD") echo $html;
