<?php

/**
 * Node 백엔드와 동일한 raw JSON 응답. PHAST 기본 래핑(success/data)을 쓰지 않는다.
 */
class WIKI_RESPONSE
{
    public $body;
    public $status;

    public function __construct( $body, $status = 200 )
    {
        $this->body = $body;
        $this->status = $status;
    }
}

function wiki_ok( $body, $status = 200 )
{
    return new WIKI_RESPONSE($body, $status);
}

function wiki_send( $body, $status = 200 )
{
    http_response_code((int) $status);
    header("Content-Type: application/json; charset=utf-8");
    header("X-Content-Type-Options: nosniff");
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

/**
 * 어디서든 즉시 에러 응답으로 종료한다. 열려 있는 트랜잭션은 PDO 종료 시 롤백된다.
 */
function wiki_abort( $status, $code, $params = [] )
{
    $body = ["error" => (string) $code];
    if (is_array($params) && count($params) > 0) {
        $body["params"] = $params;
    }
    wiki_send($body, $status);
}

/**
 * PHASTAPI 의 $G_PHASTAPI_RESPONSE_FORMATTER 훅. 모든 응답이 여기를 지난다.
 */
function wiki_format_response( $data, $false_message = "" )
{
    if ($data instanceof WIKI_RESPONSE) {
        wiki_send($data->body, $data->status);
    }

    if ($data === false || $data === null) {
        $mapped = wiki_framework_error($false_message);
        wiki_send(["error" => $mapped[1]], $mapped[0]);
    }

    if (is_array($data) && isset($data[0]) && $data[0] === false) {
        $mapped = wiki_framework_error($data[1] ?? "");
        wiki_send(["error" => $mapped[1]], $mapped[0]);
    }

    wiki_send($data, 200);
}

/**
 * 프레임워크가 내보내는 코드를 Wikiman 상태 코드·한국어 메시지로 옮긴다.
 */
function wiki_framework_error( $code )
{
    $known = [
        "NOT_MATCHED_API" => [404, "API_NOT_FOUND"],
        "UNPROCESSABLE_ENTITY" => [400, "REQUEST_INVALID"],
        "UNAUTHORIZED" => [401, "UNAUTHORIZED"],
    ];

    $key = is_string($code) ? $code : "";
    if (isset($known[$key])) {
        return $known[$key];
    }

    // 예기치 못한 예외는 메시지를 그대로 노출하지 않는다.
    return [500, "SERVER_ERROR"];
}
