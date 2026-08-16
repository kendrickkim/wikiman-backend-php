<?php

/***
 * PlantUML 소스를 SVG로 렌더링
 * @ METHOD : POST
 * @ URL : /plantuml
 * @ REQUEST BODY
 *      source|text : [string] PlantUML 소스 (최대 32KB)
 * @ RETURN : image/svg+xml
 */
#[_POST_("/plantuml")]
function wiki_plantuml_post()
{
    if (wiki_plantuml_rate_limited()) {
        wiki_abort(429, "PLANTUML_RATE_LIMITED");
    }
    $source = (string) wiki_input("source", wiki_input("text", ""));
    if (trim($source) === "") {
        wiki_abort(400, "PLANTUML_SOURCE_REQUIRED");
    }
    if (strlen($source) > WIKI_PLANTUML_MAX_SOURCE_BYTES) {
        wiki_abort(400, "PLANTUML_SOURCE_TOO_LONG");
    }
    try {
        wiki_plantuml_send_svg(wiki_plantuml_fetch_svg(wiki_plantuml_encode($source)));
    } catch ( WIKI_PLANTUML_ERROR $error ) {
        wiki_abort(502, "PLANTUML_RENDER_FAILED");
    } catch ( Throwable $error ) {
        error_log("wikiman: PlantUML rendering failed - " . $error);
        wiki_abort(502, "PLANTUML_UNREACHABLE");
    }
}

/***
 * 이미 인코딩된 PlantUML 요청을 SVG로 렌더링
 * @ METHOD : GET
 * @ URL : /plantuml/{encoded}
 * @ RETURN : image/svg+xml
 */
#[_GET_("/plantuml/{encoded}")]
function wiki_plantuml_get()
{
    if (wiki_plantuml_rate_limited()) {
        wiki_abort(429, "PLANTUML_RATE_LIMITED");
    }
    $encoded = (string) wiki_uarg("encoded");
    if (!preg_match('/^[A-Za-z0-9_-]{1,20000}$/', $encoded)) {
        wiki_abort(400, "PLANTUML_REQUEST_INVALID");
    }
    try {
        wiki_plantuml_send_svg(wiki_plantuml_fetch_svg($encoded));
    } catch ( WIKI_PLANTUML_ERROR $error ) {
        wiki_abort(502, "PLANTUML_RENDER_FAILED");
    } catch ( Throwable $error ) {
        error_log("wikiman: PlantUML rendering failed - " . $error);
        wiki_abort(502, "PLANTUML_UNREACHABLE");
    }
}
