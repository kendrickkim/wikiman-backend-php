<?php

/***
 * 업로드 파일 열기 (글에 연결되기 전 단계)
 * @ METHOD : GET
 * @ URL : /files/{name}
 */
#[_GET_("/files/{name}")]
function wiki_file_open()
{
    $name = basename((string) wiki_uarg("name"));
    $path = wiki_upload_path($name);

    if (!$path || !wiki_can_access_upload($name)) {
        wiki_abort(404, "파일을 찾을 수 없습니다.");
    }

    wiki_send_file($path);
}
