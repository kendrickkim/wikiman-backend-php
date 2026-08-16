<?php

$app_dir = rtrim((string) (getenv("WIKIMAN_APP_DIR") ?: dirname(__DIR__)), "/");
if (!is_file($app_dir . "/install.php")) {
    http_response_code(500);
    header("Content-Type: text/plain; charset=utf-8");
    echo "WIKIMAN_APP_DIR이 올바르지 않습니다.";
    exit;
}

require($app_dir . "/install.php");
