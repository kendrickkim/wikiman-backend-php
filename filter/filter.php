<?php

/**
 * WIKI_WRITER / WIKI_LOGIN 속성이 붙은 API 만 인증을 요구한다.
 * 속성이 없는 API 는 비로그인 열람이 가능하고, 필요하면 핸들러에서 wiki_current_user() 를 본다.
 */
#[FILTER(FILTER::IN, 10)]
function filter_wikiman_authorization()
{
    @include_once(__DIR__ . "/../attribute/attribute.php");

    if (!wiki_is_installed()) {
        wiki_abort(503, "INSTALL_REQUIRED", ["installUrl" => "/install.php"]);
    }

    $args = FILTER::GetArgs();
    $reflection = new ReflectionFunction($args->func);

    if (count($reflection->getAttributes(WIKI_WRITER::class)) > 0) {
        wiki_require_writer();
    } else if (count($reflection->getAttributes(WIKI_LOGIN::class)) > 0) {
        wiki_require_login();
    }

    return true;
}
