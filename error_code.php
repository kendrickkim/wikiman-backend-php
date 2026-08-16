<?php

// Wikiman 은 raw JSON(`{"error": "STABLE_CODE", "params": {...}}`) 규격을 쓰므로
// PHAST 기본 오류 래핑은 사용하지 않는다. 프레임워크 오류도 wiki_format_response()에서
// Wikiman 안정 코드로 변환한다.
$G_CUSTOM_ERROR_CODE = "";
