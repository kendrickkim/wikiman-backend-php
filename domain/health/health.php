<?php

/***
 * 헬스 체크
 * @ METHOD : GET
 * @ URL : /health
 * @ RETURN
 *      ok : [bool] 동작 여부
 */
#[_GET_("/health")]
function wiki_health()
{
    wiki_db();
    return wiki_ok(["ok" => true]);
}
