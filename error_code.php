<?php

// Wikiman 은 raw JSON(`{"error": "..."}`) 규격을 쓰므로 프레임워크 에러 코드 표는 쓰지 않는다.
// 프레임워크가 자체적으로 내보내는 코드만 wiki_format_response() 에서 한국어 메시지로 변환한다.
$G_CUSTOM_ERROR_CODE = "";
