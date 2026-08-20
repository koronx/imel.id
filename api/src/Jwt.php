<?php

namespace Imel\Api;

/** Minimal HS256 JSON Web Token encoder/decoder (no external dependency). */
final class Jwt
{
    public static function secret(): string
    {
        $secret = getenv('JWT_SECRET') ?: '';
        return $secret !== '' ? $secret : 'imel-dev-secret-change-me';
    }

    public static function ttl(): int
    {
        return (int) (getenv('JWT_TTL') ?: 604800);   // 7 days
    }

    public static function encode(array $payload): string
    {
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $claims = self::b64(json_encode($payload));
        $signature = self::b64(hash_hmac('sha256', "{$header}.{$claims}", self::secret(), true));

        return "{$header}.{$claims}.{$signature}";
    }

    /** @return array|null decoded claims, or null when invalid/expired */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $claims, $signature] = $parts;

        $expected = self::b64(hash_hmac('sha256', "{$header}.{$claims}", self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode(self::b64decode($claims), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
