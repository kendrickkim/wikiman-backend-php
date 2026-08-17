<?php

const WIKI_THUMBNAIL_MAX_WIDTH = 400;

function wiki_thumbnail_cache_days()
{
    $value = wiki_db()->query(
        "SELECT `value` FROM settings WHERE `key` = 'thumbnail_cache_days'"
    )->fetchColumn();
    return max(1, min(365, (int) ($value ?: 100)));
}

function wiki_thumbnail_cache_path( $name )
{
    $base = basename((string) $name);
    if ($base === "" || $base !== $name) return null;
    $extension = function_exists("imagewebp") ? "webp" : "png";
    return wiki_config("thumbnails") . "/" . hash("sha256", $base) . "." . $extension;
}

function wiki_delete_thumbnail( $name )
{
    $base = basename((string) $name);
    if ($base === "" || $base !== $name) return;
    $prefix = wiki_config("thumbnails") . "/" . hash("sha256", $base);
    foreach (["webp", "png"] as $extension) {
        $path = $prefix . "." . $extension;
        if (is_file($path)) @unlink($path);
    }
}

function wiki_thumbnail_source_image( $path, $type )
{
    $loaders = [
        IMAGETYPE_JPEG => "imagecreatefromjpeg",
        IMAGETYPE_PNG => "imagecreatefrompng",
        IMAGETYPE_GIF => "imagecreatefromgif",
        IMAGETYPE_WEBP => "imagecreatefromwebp",
    ];
    $loader = $loaders[$type] ?? null;
    if (!$loader || !function_exists($loader)) return null;
    return @$loader($path) ?: null;
}

function wiki_generate_thumbnail( $source, $target )
{
    if (!extension_loaded("gd") || !function_exists("getimagesize")) return false;
    $info = @getimagesize($source);
    if (!$info || empty($info[0]) || empty($info[1])) return false;
    if (!in_array((int) $info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        return false;
    }

    $image = wiki_thumbnail_source_image($source, (int) $info[2]);
    if (!$image) return false;

    $width = (int) $info[0];
    $height = (int) $info[1];
    $target_width = min(WIKI_THUMBNAIL_MAX_WIDTH, $width);
    $target_height = max(1, (int) round($height * ($target_width / $width)));
    $thumbnail = @imagecreatetruecolor($target_width, $target_height);
    if (!$thumbnail) {
        unset($image);
        return false;
    }

    imagealphablending($thumbnail, false);
    imagesavealpha($thumbnail, true);
    $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
    imagefilledrectangle($thumbnail, 0, 0, $target_width, $target_height, $transparent);
    $resampled = @imagecopyresampled(
        $thumbnail, $image, 0, 0, 0, 0,
        $target_width, $target_height, $width, $height
    );
    unset($image);
    if (!$resampled) {
        unset($thumbnail);
        return false;
    }

    wiki_make_dir(dirname($target));
    @chmod(dirname($target), 0750);
    $temp = $target . ".tmp-" . bin2hex(random_bytes(6));
    $written = function_exists("imagewebp")
        ? @imagewebp($thumbnail, $temp, 82)
        : @imagepng($thumbnail, $temp, 6);
    unset($thumbnail);
    if (!$written || !is_file($temp)) {
        @unlink($temp);
        return false;
    }
    @chmod($temp, 0640);
    if (!@rename($temp, $target)) {
        @unlink($temp);
        return false;
    }
    @chmod($target, 0640);
    return true;
}

/**
 * 유효한 캐시를 돌려주고, 만들 수 없는 형식이나 GD 오류는 원본으로 폴백한다.
 */
function wiki_thumbnail_file( $source, $name )
{
    $target = wiki_thumbnail_cache_path($name);
    if (!$target) return null;

    $source_mtime = (int) (@filemtime($source) ?: 0);
    $cache_mtime = is_file($target) ? (int) (@filemtime($target) ?: 0) : 0;
    $ttl = wiki_thumbnail_cache_days() * 86400;
    if ($cache_mtime >= $source_mtime && $cache_mtime >= time() - $ttl) {
        return $target;
    }

    if (is_file($target)) @unlink($target);
    try {
        return wiki_generate_thumbnail($source, $target) ? $target : null;
    } catch ( Throwable $error ) {
        error_log("wikiman: thumbnail generation failed - " . $error->getMessage());
        return null;
    }
}

function wiki_thumbnail_requested()
{
    $value = wiki_query("thumb", "");
    return $value === 1 || $value === true || strtolower((string) $value) === "true"
        || (string) $value === "1";
}

function wiki_send_thumbnail_or_original( $source, $name )
{
    if (!wiki_thumbnail_requested()) {
        wiki_send_file($source);
    }

    $days = wiki_thumbnail_cache_days();
    $options = [
        "cache_control" => "public, max-age=" . ($days * 86400),
        "conditional" => true,
    ];
    $thumbnail = wiki_thumbnail_file($source, $name);
    if (!$thumbnail) {
        wiki_send_file($source, null, null, $options);
    }

    wiki_send_file(
        $thumbnail,
        null,
        function_exists("imagewebp") ? "image/webp" : "image/png",
        $options
    );
}
