<?php

/**
 * 로그인한 사용자만 호출할 수 있는 API
 */
#[Attribute]
class WIKI_LOGIN
{
    public function __construct()
    {
    }
}

/**
 * 위키 작성자(writer)만 호출할 수 있는 API
 */
#[Attribute]
class WIKI_WRITER
{
    public function __construct()
    {
    }
}
