<?php

require_once(__DIR__ . "/libs/common/wiki.response.php");
require_once(__DIR__ . "/libs/common/wiki.config.php");
require_once(__DIR__ . "/libs/common/wiki.db.php");
require_once(__DIR__ . "/libs/installer/wiki.installer.php");

$reason = null;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    try {
        wiki_installer_submit($_POST);
        header("Location: /", true, 303);
        exit;
    } catch ( Throwable $error ) {
        wiki_installer_render($error->getMessage(), 400);
    }
}

if (wiki_installer_is_healthy($reason)) {
    header("Location: /", true, 302);
    exit;
}

$message = "";
if ((wiki_is_installed() || is_file(wiki_config_file())) && !wiki_installer_is_healthy($reason)) {
    $message = "기존 설정이 있지만 DB/관리자 상태를 확인할 수 없습니다. "
        . "WIKIMAN_INSTALL_TOKEN을 서버에 설정한 뒤 아래 양식으로 설정을 복구할 수 있습니다. "
        . "이미 있는 테이블은 유지됩니다.";
}
wiki_installer_render($message);
