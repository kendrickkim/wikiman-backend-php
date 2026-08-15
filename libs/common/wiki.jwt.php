<?php

/**
 * Node 백엔드와 호환되는 HS256 JWT.
 */
class WIKI_JWT
{
    public static function Sign( $user, $secret, $ttl )
    {
        $now = time();
        $header = ["alg" => "HS256", "typ" => "JWT"];
        $payload = [
            "id" => (int) $user["id"],
            "username" => (string) $user["username"],
            "role" => ($user["role"] ?? "") === "writer" ? "writer" : "reader",
            "canWrite" => ($user["canWrite"] ?? false) === true,
            "iat" => $now,
            "exp" => $now + $ttl,
        ];

        $segments = [self::Encode(json_encode($header)), self::Encode(json_encode($payload))];
        $segments[] = self::Encode(hash_hmac("sha256", implode(".", $segments), $secret, true));
        return implode(".", $segments);
    }

    public static function Verify( $token, $secret )
    {
        $parts = explode(".", $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($encoded_header, $encoded_payload, $encoded_signature) = $parts;
        $header = json_decode(self::Decode($encoded_header), true);
        $payload = json_decode(self::Decode($encoded_payload), true);

        if (!is_array($header) || ($header["alg"] ?? null) !== "HS256" || !is_array($payload)) {
            return null;
        }

        $expected = hash_hmac("sha256", $encoded_header . "." . $encoded_payload, $secret, true);
        if (!hash_equals($expected, self::Decode($encoded_signature))) {
            return null;
        }

        if (!isset($payload["exp"], $payload["id"]) || (int) $payload["exp"] <= time()) {
            return null;
        }

        return $payload;
    }

    private static function Encode( $value )
    {
        return rtrim(strtr(base64_encode($value), "+/", "-_"), "=");
    }

    private static function Decode( $value )
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat("=", 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, "-_", "+/"), true);
        return $decoded === false ? "" : $decoded;
    }
}
