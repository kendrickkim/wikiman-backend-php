<?php

/**
 * 요청 본문을 연관 배열로 돌려준다. PHASTAPI 는 stdClass 로 넘긴다.
 */
function wiki_body()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $body = REQUEST::GetReqBody();
    if (is_object($body)) {
        $cached = json_decode(json_encode($body), true);
    } else if (is_array($body)) {
        $cached = $body;
    } else {
        $cached = [];
    }

    return is_array($cached) ? $cached : ($cached = []);
}

function wiki_has( $key )
{
    return array_key_exists($key, wiki_body());
}

function wiki_input( $key, $default = null )
{
    $body = wiki_body();
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

/**
 * PHASTAPI 의 GetQstring 은 URL 디코딩을 하지 않아 $_GET 을 그대로 쓴다.
 */
function wiki_query( $name, $default = null )
{
    return isset($_GET[$name]) ? $_GET[$name] : $default;
}

function wiki_uarg( $name )
{
    $uargs = REQUEST::GetUargs();
    return is_object($uargs) && isset($uargs->$name) ? $uargs->$name : null;
}

function wiki_uarg_int( $name )
{
    return (int) wiki_uarg($name);
}
