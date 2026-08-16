<?php

/***
 * 링크 미리보기
 * @ METHOD : GET
 * @ URL : /link-preview
 * @ QUERY STRINGS
 *      url : [string] http(s) 페이지 주소
 * @ RETURN
 *      preview : [object] url, title, description, image, siteName
 */
#[_GET_("/link-preview")]
function wiki_link_preview_get()
{
    if (wiki_preview_rate_limited()) {
        wiki_abort(429, "RATE_LIMITED");
    }

    try {
        return wiki_ok(["preview" => wiki_fetch_link_preview(wiki_query("url"))]);
    } catch ( WIKI_PREVIEW_ERROR $error ) {
        wiki_abort($error->status, "LINK_PREVIEW_FAILED");
    } catch ( Throwable $error ) {
        error_log("wikiman: link preview failed - " . $error);
        wiki_abort(400, "LINK_PREVIEW_FAILED");
    }
}

/***
 * 링크 미리보기 캐시 통계
 * @ METHOD : GET
 * @ URL : /link-preview/cache
 * @ RETURN
 *      count, ttlDays, failureTtlDays
 */
#[_GET_("/link-preview/cache"), WIKI_WRITER]
function wiki_link_preview_cache_get()
{
    return wiki_ok(wiki_preview_cache_stats());
}

/***
 * 링크 미리보기 캐시 삭제
 * @ METHOD : DELETE
 * @ URL : /link-preview/cache
 * @ RETURN
 *      deleted, ttlDays, failureTtlDays
 */
#[_DELETE_("/link-preview/cache"), WIKI_WRITER]
function wiki_link_preview_cache_delete()
{
    return wiki_ok(wiki_preview_cache_clear());
}
