<?php

const WIKI_PLANTUML_MAX_SOURCE_BYTES = 32768;
const WIKI_PLANTUML_MAX_SVG_BYTES = 2097152;
const WIKI_PLANTUML_TIMEOUT_SECONDS = 12;

class WIKI_PLANTUML_ERROR extends RuntimeException
{
}

function wiki_plantuml_fail( $message )
{
    throw new WIKI_PLANTUML_ERROR($message);
}

function wiki_plantuml_rate_limited()
{
    $key = "ip:" . (string) ($_SERVER["REMOTE_ADDR"] ?? "local");
    $now = time();
    $cutoff = $now - 60;
    $db = wiki_db();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM plantuml_rate WHERE hit_at < ?")->execute([$cutoff]);
        $stmt = $db->prepare("SELECT COUNT(*) FROM plantuml_rate WHERE client_key = ? AND hit_at >= ?");
        $stmt->execute([$key, $cutoff]);
        $limited = (int) $stmt->fetchColumn() >= 20;
        if (!$limited) {
            $db->prepare("INSERT INTO plantuml_rate (client_key, hit_at) VALUES (?, ?)")->execute([$key, $now]);
        }
        $db->commit();
        return $limited;
    } catch ( Throwable $error ) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("wikiman: PlantUML rate limiter failed - " . $error->getMessage());
        return false;
    }
}

function wiki_plantuml_encode6bit( $value )
{
    if ($value < 10) return chr(48 + $value);
    $value -= 10;
    if ($value < 26) return chr(65 + $value);
    $value -= 26;
    if ($value < 26) return chr(97 + $value);
    return $value === 26 ? "-" : "_";
}

/**
 * plantuml-encoder와 같은 raw DEFLATE + PlantUML base64 인코딩.
 */
function wiki_plantuml_encode( $source )
{
    $compressed = gzdeflate($source, 9);
    if ($compressed === false) {
        wiki_plantuml_fail("PlantUML 소스를 압축할 수 없습니다.");
    }
    $result = "";
    $length = strlen($compressed);
    for ($i = 0; $i < $length; $i += 3) {
        $b1 = ord($compressed[$i]);
        $b2 = $i + 1 < $length ? ord($compressed[$i + 1]) : 0;
        $b3 = $i + 2 < $length ? ord($compressed[$i + 2]) : 0;
        $result .= wiki_plantuml_encode6bit($b1 >> 2);
        $result .= wiki_plantuml_encode6bit((($b1 & 0x3) << 4) | ($b2 >> 4));
        if ($i + 1 < $length) {
            $result .= wiki_plantuml_encode6bit((($b2 & 0xF) << 2) | ($b3 >> 6));
        }
        if ($i + 2 < $length) {
            $result .= wiki_plantuml_encode6bit($b3 & 0x3F);
        }
    }
    return $result;
}

function wiki_plantuml_server()
{
    $server = rtrim((string) wiki_settings()["plantumlServer"], "/");
    if (!wiki_preview_normalize_url($server)) {
        wiki_plantuml_fail("PlantUML 서버 주소가 올바르지 않습니다.");
    }
    return $server;
}

function wiki_plantuml_fetch_svg( $encoded )
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,20000}$/', $encoded)) {
        wiki_plantuml_fail("PlantUML 요청이 올바르지 않습니다.");
    }
    $current = wiki_plantuml_server() . "/svg/" . $encoded;

    for ($redirects = 0; $redirects <= 5; $redirects++) {
        $normalized = wiki_preview_normalize_url($current);
        if (!$normalized) {
            wiki_plantuml_fail("PlantUML 서버 주소가 올바르지 않습니다.");
        }
        $parts = parse_url($normalized);
        $host = (string) $parts["host"];
        try {
            $ip = wiki_preview_resolve_public_host($host);
        } catch ( WIKI_PREVIEW_ERROR $error ) {
            wiki_plantuml_fail("PlantUML 서버에 연결할 수 없습니다.");
        }
        $scheme = strtolower((string) $parts["scheme"]);
        $port = isset($parts["port"]) ? (int) $parts["port"] : ($scheme === "https" ? 443 : 80);

        $body = "";
        $headers = [];
        $too_large = false;
        $ch = curl_init($normalized);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => WIKI_PLANTUML_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => WIKI_PLANTUML_TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ":" . $port . ":" . $ip],
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                $length = strlen($line);
                $pos = strpos($line, ":");
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$too_large) {
                if (strlen($body) + strlen($chunk) > WIKI_PLANTUML_MAX_SVG_BYTES) {
                    $too_large = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error_no = curl_errno($ch);
        curl_close($ch);

        if ($too_large) {
            wiki_plantuml_fail("PlantUML 결과가 너무 큽니다.");
        }
        if ($ok === false) {
            if ($error_no === CURLE_OPERATION_TIMEDOUT) {
                wiki_plantuml_fail("PlantUML 서버 응답이 지연되었습니다.");
            }
            wiki_plantuml_fail("PlantUML 서버에 연결할 수 없습니다.");
        }
        if ($status >= 300 && $status < 400) {
            $next = wiki_preview_normalize_url(
                wiki_preview_absolute_url($normalized, $headers["location"] ?? "")
            );
            if (!$next) {
                wiki_plantuml_fail("PlantUML 서버에 연결할 수 없습니다.");
            }
            $current = $next;
            continue;
        }
        if ($status < 200 || $status >= 300) {
            wiki_plantuml_fail("PlantUML 렌더링에 실패했습니다.");
        }
        return $body;
    }
    wiki_plantuml_fail("PlantUML 서버에 연결할 수 없습니다.");
}

function wiki_plantuml_send_svg( $svg )
{
    header("Content-Type: image/svg+xml; charset=utf-8");
    header("X-Content-Type-Options: nosniff");
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    header("Cache-Control: public, max-age=86400");
    echo $svg;
    exit(0);
}
