<?php

/***
 * 전체 데이터 백업 내려받기
 * @ METHOD : GET
 * @ URL : /backup/download
 * @ RETURN : application/octet-stream (.wkmbak)
 */
#[_GET_("/backup/download"), WIKI_WRITER]
function wiki_backup_download()
{
    $path = wiki_config("data_dir") . "/tmp-backup-" . bin2hex(random_bytes(8)) . WIKI_BACKUP_EXTENSION;
    try {
        wiki_backup_create($path);
        $now = new DateTimeImmutable("now", new DateTimeZone("Asia/Seoul"));
        $name = "wikiman-backup-" . $now->format("Ymd-His") . WIKI_BACKUP_EXTENSION;

        header("Content-Type: application/octet-stream");
        header("X-Content-Type-Options: nosniff");
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
        header("Content-Length: " . filesize($path));
        readfile($path);
        @unlink($path);
        exit(0);
    } catch ( WIKI_BACKUP_ERROR $error ) {
        @unlink($path);
        wiki_abort($error->status, $error->getMessage());
    } catch ( Throwable $error ) {
        @unlink($path);
        error_log("wikiman: backup download failed - " . $error);
        wiki_abort(500, "백업에 실패했습니다.");
    }
}

/***
 * 백업 파일 검사 (복구하지 않음)
 * @ METHOD : POST
 * @ URL : /backup/inspect
 * @ REQUEST BODY : multipart/form-data
 *      backup : [file] .wkmbak 파일
 * @ RETURN
 *      ok, formatVersion, schemaVersion, createdAt, fileCount,
 *      totalBytes, uploadCount, payloadSha256
 */
#[_POST_("/backup/inspect"), WIKI_WRITER]
function wiki_backup_inspect_route()
{
    try {
        return wiki_ok(wiki_backup_inspect(wiki_backup_uploaded_file()));
    } catch ( WIKI_BACKUP_ERROR $error ) {
        wiki_abort($error->status, $error->getMessage());
    } catch ( Throwable $error ) {
        error_log("wikiman: backup inspect failed - " . $error);
        wiki_abort(400, "백업 파일을 확인할 수 없습니다.");
    }
}

/***
 * 백업으로 DB와 업로드 전체 복구
 * @ METHOD : POST
 * @ URL : /backup/restore
 * @ REQUEST BODY : multipart/form-data
 *      backup : [file] 검사할 때 사용한 .wkmbak 파일
 * @ RETURN
 *      복구 정보 + settings
 */
#[_POST_("/backup/restore"), WIKI_WRITER]
function wiki_backup_restore_route()
{
    try {
        $path = wiki_backup_uploaded_file();
        // 원본과 동일하게 실제 교체 전에 전체 구조를 먼저 검사한다.
        wiki_backup_inspect($path);
        $result = wiki_backup_restore($path);
        $result["settings"] = wiki_settings(wiki_current_user());
        return wiki_ok($result);
    } catch ( WIKI_BACKUP_ERROR $error ) {
        wiki_abort($error->status, $error->getMessage());
    } catch ( Throwable $error ) {
        error_log("wikiman: backup restore failed - " . $error);
        wiki_abort(400, "복구에 실패했습니다.");
    }
}
