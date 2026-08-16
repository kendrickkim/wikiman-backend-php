<?php

const WIKI_PREVIEW_TIMEOUT_SECONDS = 8;
const WIKI_PREVIEW_MAX_BYTES = 524288;
const WIKI_PREVIEW_MAX_REDIRECTS = 5;

class WIKI_PREVIEW_ERROR extends RuntimeException
{
    public $status;

    public function __construct( $message, $status = 400 )
    {
        parent::__construct($message);
        $this->status = (int) $status;
    }
}

function wiki_preview_fail( $message, $status = 400 )
{
    throw new WIKI_PREVIEW_ERROR($message, $status);
}

function wiki_preview_cache_config()
{
    $settings = wiki_settings();
    return [
        "ttlDays" => max(1, min(365, (int) $settings["linkPreviewCacheTtlDays"])),
        "failureTtlDays" => max(1, min(365, (int) $settings["linkPreviewFailureTtlDays"])),
    ];
}

function wiki_preview_cache_stats()
{
    $count = (int) wiki_db()->query("SELECT COUNT(*) FROM link_preview_cache")->fetchColumn();
    return array_merge(["count" => $count], wiki_preview_cache_config());
}

function wiki_preview_cache_clear()
{
    $deleted = wiki_db()->exec("DELETE FROM link_preview_cache");
    return array_merge(["deleted" => (int) $deleted], wiki_preview_cache_config());
}

/**
 * 1분에 30회. PHP 프로세스가 여러 개여도 DB를 통해 제한을 공유한다.
 */
function wiki_preview_rate_limited()
{
    $user = wiki_current_user();
    $key = $user ? "user:" . (int) $user["id"] : "ip:" . (string) ($_SERVER["REMOTE_ADDR"] ?? "local");
    $now = time();
    $cutoff = $now - 60;
    $db = wiki_db();

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM link_preview_rate WHERE hit_at < ?")->execute([$cutoff]);
        $stmt = $db->prepare("SELECT COUNT(*) FROM link_preview_rate WHERE client_key = ? AND hit_at >= ?");
        $stmt->execute([$key, $cutoff]);
        $limited = (int) $stmt->fetchColumn() >= 30;
        if (!$limited) {
            $db->prepare("INSERT INTO link_preview_rate (client_key, hit_at) VALUES (?, ?)")->execute([$key, $now]);
        }
        $db->commit();
        return $limited;
    } catch ( Throwable $error ) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // 제한 장치 장애가 실제 미리보기 기능을 막지는 않게 한다.
        error_log("wikiman: link preview rate limiter failed - " . $error->getMessage());
        return false;
    }
}

/**
 * http(s)만 허용하고 사용자 정보, 로컬 호스트, fragment를 제거한다.
 */
function wiki_preview_normalize_url( $raw )
{
    $url = trim((string) $raw);
    if ($url === "" || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts["scheme"] ?? ""));
    $host = strtolower(rtrim((string) ($parts["host"] ?? ""), "."));
    if (!in_array($scheme, ["http", "https"], true) || $host === "") {
        return null;
    }
    if (isset($parts["user"]) || isset($parts["pass"])) {
        return null;
    }
    if ($host === "localhost" || str_ends_with($host, ".localhost") || str_ends_with($host, ".local")) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) && !wiki_preview_is_public_ip($host)) {
        return null;
    }

    // fragment는 서버 요청과 캐시 키에 필요하지 않다.
    return preg_replace('/#.*$/', "", $url);
}

/**
 * 사설망뿐 아니라 loopback, link-local, multicast, 문서용 예약 대역도 막는다.
 */
function wiki_preview_is_public_ip( $ip )
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * 호스트의 모든 A/AAAA 레코드가 공인 주소인지 확인하고 cURL에 고정할 주소를 반환한다.
 * 검증 후 DNS가 바뀌는 rebinding 공격을 막기 위해 실제 접속에도 같은 IP를 사용한다.
 */
function wiki_preview_resolve_public_host( $host )
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!wiki_preview_is_public_ip($host)) {
            wiki_preview_fail("내부 주소는 미리볼 수 없습니다.");
        }
        return $host;
    }

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records) || !$records) {
        wiki_preview_fail("주소를 확인할 수 없습니다.");
    }

    $addresses = [];
    foreach ($records as $record) {
        $address = $record["ip"] ?? $record["ipv6"] ?? null;
        if ($address !== null) {
            $addresses[] = $address;
        }
    }
    if (!$addresses) {
        wiki_preview_fail("주소를 확인할 수 없습니다.");
    }
    foreach ($addresses as $address) {
        if (!wiki_preview_is_public_ip($address)) {
            wiki_preview_fail("내부 주소는 미리볼 수 없습니다.");
        }
    }

    // IPv4를 우선하면 IPv6 라우팅이 없는 공유 호스팅에서도 안정적이다.
    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $address;
        }
    }
    return $addresses[0];
}

function wiki_preview_absolute_url( $base, $value )
{
    $value = trim((string) $value);
    if ($value === "") {
        return "";
    }
    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return $value;
    }

    $base_parts = parse_url($base);
    if (!$base_parts || empty($base_parts["host"])) {
        return "";
    }
    $origin = $base_parts["scheme"] . "://" . $base_parts["host"];
    if (isset($base_parts["port"])) {
        $origin .= ":" . $base_parts["port"];
    }
    if (str_starts_with($value, "//")) {
        return $base_parts["scheme"] . ":" . $value;
    }
    if (str_starts_with($value, "/")) {
        return $origin . $value;
    }

    $path = $base_parts["path"] ?? "/";
    $directory = preg_replace('#/[^/]*$#', "/", $path);
    $combined = $directory . $value;
    $segments = [];
    foreach (explode("/", $combined) as $segment) {
        if ($segment === "" || $segment === ".") {
            continue;
        }
        if ($segment === "..") {
            array_pop($segments);
        } else {
            $segments[] = $segment;
        }
    }
    return $origin . "/" . implode("/", $segments);
}

/**
 * 리다이렉트를 수동으로 따라가며 매 단계 DNS/주소를 다시 검증한다.
 */
function wiki_preview_fetch_page( $initial_url )
{
    $current = $initial_url;

    for ($redirects = 0; $redirects <= WIKI_PREVIEW_MAX_REDIRECTS; $redirects++) {
        $parts = parse_url($current);
        $host = (string) $parts["host"];
        $scheme = strtolower((string) $parts["scheme"]);
        $port = isset($parts["port"]) ? (int) $parts["port"] : ($scheme === "https" ? 443 : 80);
        $ip = wiki_preview_resolve_public_host($host);

        $body = "";
        $headers = [];
        $too_large = false;
        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => WIKI_PREVIEW_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => WIKI_PREVIEW_TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                "Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
                "User-Agent: WikimanLinkPreview/1.0",
            ],
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
                if (strlen($body) + strlen($chunk) > WIKI_PREVIEW_MAX_BYTES) {
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

        if ($too_large || (isset($headers["content-length"]) && (int) $headers["content-length"] > WIKI_PREVIEW_MAX_BYTES)) {
            wiki_preview_fail("페이지가 너무 큽니다.");
        }
        if ($ok === false) {
            if ($error_no === CURLE_OPERATION_TIMEDOUT) {
                wiki_preview_fail("페이지 응답이 너무 늦습니다.");
            }
            wiki_preview_fail("페이지를 가져오지 못했습니다.");
        }

        if ($status >= 300 && $status < 400) {
            $location = $headers["location"] ?? "";
            $next = wiki_preview_normalize_url(wiki_preview_absolute_url($current, $location));
            if (!$next) {
                wiki_preview_fail("이동할 페이지 주소가 올바르지 않습니다.");
            }
            $current = $next;
            continue;
        }

        if ($status < 200 || $status >= 300) {
            wiki_preview_fail("페이지를 가져오지 못했습니다.");
        }
        $type = strtolower((string) ($headers["content-type"] ?? ""));
        if (strpos($type, "text/html") === false && strpos($type, "application/xhtml") === false) {
            wiki_preview_fail("HTML 페이지가 아닙니다.");
        }

        return ["html" => $body, "url" => $current];
    }

    wiki_preview_fail("페이지 이동 횟수가 너무 많습니다.");
}

function wiki_preview_meta( DOMXPath $xpath, $keys )
{
    foreach ($keys as $key) {
        $escaped = strtolower($key);
        $query = "//meta[translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$escaped}'"
            . " or translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='{$escaped}']/@content";
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            $value = trim(html_entity_decode($nodes->item(0)->nodeValue, ENT_QUOTES | ENT_HTML5, "UTF-8"));
            if ($value !== "") {
                return $value;
            }
        }
    }
    return "";
}

function wiki_preview_parse_html( $html, $final_url )
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);

    $host = (string) parse_url($final_url, PHP_URL_HOST);
    $title = wiki_preview_meta($xpath, ["og:title", "twitter:title"]);
    if ($title === "") {
        $nodes = $dom->getElementsByTagName("title");
        $title = $nodes->length ? trim($nodes->item(0)->textContent) : $host;
    }
    $description = wiki_preview_meta($xpath, ["og:description", "twitter:description", "description"]);
    $image = wiki_preview_absolute_url(
        $final_url,
        wiki_preview_meta($xpath, ["og:image", "twitter:image", "twitter:image:src"])
    );
    $site_name = wiki_preview_meta($xpath, ["og:site_name"]) ?: $host;

    return [
        "url" => $final_url,
        "title" => mb_substr(preg_replace('/\s+/u', " ", $title), 0, 200),
        "description" => mb_substr($description, 0, 400),
        "image" => mb_substr($image, 0, 1000),
        "siteName" => mb_substr($site_name, 0, 120),
    ];
}

function wiki_preview_cache_get( $key, $ttl_days )
{
    $mysql = wiki_db_driver() === "mysql";
    $stmt = wiki_db()->prepare($mysql
        ? "SELECT final_url, title, description, image, site_name,
                  fetched_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY) AS is_fresh
           FROM link_preview_cache WHERE url = ?"
        : "SELECT final_url, title, description, image, site_name,
                  datetime(fetched_at) > datetime('now', ?) AS is_fresh
           FROM link_preview_cache WHERE url = ?");
    $stmt->execute([$mysql ? (int) $ttl_days : "-" . (int) $ttl_days . " days", $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return ["value" => null, "fresh" => false];
    }
    return [
        "value" => [
            "url" => $row["final_url"],
            "title" => $row["title"],
            "description" => $row["description"],
            "image" => $row["image"],
            "siteName" => $row["site_name"],
        ],
        "fresh" => (int) $row["is_fresh"] === 1,
    ];
}

function wiki_preview_cache_set( $key, $preview )
{
    $stmt = wiki_db()->prepare(wiki_db_driver() === "mysql"
        ? "INSERT INTO link_preview_cache (url, final_url, title, description, image, site_name, fetched_at)
           VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
           ON DUPLICATE KEY UPDATE final_url = VALUES(final_url), title = VALUES(title),
             description = VALUES(description), image = VALUES(image), site_name = VALUES(site_name),
             fetched_at = CURRENT_TIMESTAMP"
        : "INSERT INTO link_preview_cache (url, final_url, title, description, image, site_name, fetched_at)
           VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
           ON CONFLICT(url) DO UPDATE SET final_url = excluded.final_url, title = excluded.title,
             description = excluded.description, image = excluded.image, site_name = excluded.site_name,
             fetched_at = datetime('now')");
    $stmt->execute([
        $key, $preview["url"], $preview["title"], $preview["description"],
        $preview["image"], $preview["siteName"],
    ]);
}

function wiki_preview_extend_failed_cache( $key, $ttl_days, $failure_ttl_days )
{
    $offset = (int) $failure_ttl_days - (int) $ttl_days;
    $mysql = wiki_db_driver() === "mysql";
    $stmt = wiki_db()->prepare($mysql
        ? "UPDATE link_preview_cache
           SET fetched_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? DAY) WHERE url = ?"
        : "UPDATE link_preview_cache SET fetched_at = datetime('now', ?) WHERE url = ?");
    $stmt->execute([$mysql ? $offset : $offset . " days", $key]);
}

function wiki_fetch_link_preview( $raw_url )
{
    $url = wiki_preview_normalize_url($raw_url);
    if (!$url) {
        wiki_preview_fail("미리볼 수 있는 http(s) 주소가 아닙니다.");
    }

    $config = wiki_preview_cache_config();
    $cached = wiki_preview_cache_get($url, $config["ttlDays"]);
    if ($cached["fresh"]) {
        return $cached["value"];
    }

    try {
        $page = wiki_preview_fetch_page($url);
        $preview = wiki_preview_parse_html($page["html"], $page["url"]);
        wiki_preview_cache_set($url, $preview);
        return $preview;
    } catch ( Throwable $error ) {
        if ($cached["value"]) {
            wiki_preview_extend_failed_cache($url, $config["ttlDays"], $config["failureTtlDays"]);
            return $cached["value"];
        }
        throw $error;
    }
}
