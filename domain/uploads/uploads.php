<?php

/***
 * 본문 이미지 업로드 (에디터용)
 * @ METHOD : POST
 * @ URL : /uploads
 * @ REQUEST BODY : multipart/form-data
 *      image : [file] 이미지 파일
 * @ RETURN
 *      url : [string] 업로드된 파일 주소
 */
#[_POST_("/uploads"), WIKI_WRITER]
function wiki_upload_image()
{
    $files = wiki_upload_entries("image");
    if (!$files) {
        wiki_abort(400, "IMAGE_REQUIRED");
    }

    $file = wiki_store_upload(
        $files[0],
        wiki_max_attachment_mb() * 1024 * 1024,
        array_keys(WIKI_IMAGE_MIME_TYPES),
        "IMAGE_TYPE_INVALID"
    );

    return wiki_ok([
        "success" => 1,
        "file" => ["url" => $file["url"]],
        "url" => $file["url"],
    ], 201);
}

/***
 * 파비콘 업로드
 * @ METHOD : POST
 * @ URL : /uploads/favicon
 * @ RETURN
 *      url : [string] 업로드된 파비콘 주소
 */
#[_POST_("/uploads/favicon"), WIKI_WRITER]
function wiki_upload_favicon()
{
    $files = wiki_upload_entries("image");
    if (!$files) {
        wiki_abort(400, "FAVICON_REQUIRED");
    }

    $allowed = array_merge(array_keys(WIKI_IMAGE_MIME_TYPES), ["image/x-icon", "image/vnd.microsoft.icon"]);
    $file = wiki_store_upload(
        $files[0],
        2 * 1024 * 1024,
        $allowed,
        "FAVICON_TYPE_INVALID",
        true
    );

    return wiki_ok(["url" => $file["url"]], 201);
}

/***
 * 첨부파일 업로드 (한 번에 최대 20개)
 * @ METHOD : POST
 * @ URL : /uploads/files
 * @ RETURN
 *      files : [array] 저장된 파일 정보
 */
#[_POST_("/uploads/files"), WIKI_WRITER]
function wiki_upload_files()
{
    $entries = wiki_upload_entries("files");
    if (!$entries) {
        wiki_abort(400, "FILES_REQUIRED");
    }
    if (count($entries) > WIKI_MAX_UPLOAD_FILES) {
        wiki_abort(400, "UPLOAD_TOO_MANY", ["max" => 20]);
    }

    $max = wiki_max_attachment_mb() * 1024 * 1024;
    foreach ($entries as $entry) {
        wiki_check_upload($entry, $max);
    }

    $stored = [];
    foreach ($entries as $entry) {
        $stored[] = wiki_store_upload($entry, $max);
    }

    return wiki_ok(["files" => $stored], 201);
}

/***
 * 어떤 글에도 연결되지 않은 파일 목록
 * @ METHOD : GET
 * @ URL : /uploads/orphans
 * @ RETURN
 *      count, totalBytes, files
 */
#[_GET_("/uploads/orphans"), WIKI_WRITER]
function wiki_upload_orphans()
{
    $files = wiki_orphan_uploads();
    return wiki_ok([
        "count" => count($files),
        "totalBytes" => array_sum(array_column($files, "size")),
        "files" => $files,
    ]);
}

/***
 * 연결되지 않은 파일 정리
 * @ METHOD : POST
 * @ URL : /uploads/orphans/cleanup
 * @ RETURN
 *      deletedCount, deletedBytes, files
 */
#[_POST_("/uploads/orphans/cleanup"), WIKI_WRITER]
function wiki_upload_orphans_cleanup()
{
    $files = wiki_orphan_uploads();
    foreach ($files as $file) {
        $path = wiki_upload_path($file["name"]);
        if ($path) {
            @unlink($path);
            wiki_delete_thumbnail($file["name"]);
        }
    }

    return wiki_ok([
        "deletedCount" => count($files),
        "deletedBytes" => array_sum(array_column($files, "size")),
        "files" => array_column($files, "name"),
    ]);
}
