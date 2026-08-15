<?php

$G_PHASTAPI_DOMAIN_DIR = __DIR__ . "/domain";
$G_PHASTAPI_LOGPATH = __DIR__ . "/data/log";
$G_PHASTAPI_BASEURL = "/api";

$G_TIMEZONE = "Asia/Seoul";

$G_LOGGING_REQUEST = false;
$G_LOGGING_RESPONSE = false;

// 프론트와 같은 오리진(/api)에서 동작하므로 CORS는 끈다.
$G_CROSS_ORIGIN_ENABLE = false;

// /api/docs, /api/entries 노출 (운영에서는 false 권장)
$G_SHOW_API_ENTRY = true;

// Node 백엔드와 동일한 응답 규격(raw JSON)을 사용한다.
$G_PHASTAPI_RESPONSE_FORMATTER = "wiki_format_response";

// php8 attribute
include_all_files_in_dir(__DIR__ . "/attribute");

include_once(__DIR__ . "/error_code.php");
