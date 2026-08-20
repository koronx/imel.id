<?php

namespace Imel\Api;

use Imel\Api\Http\Request;
use Imel\Shared\Db;
use Imel\Shared\Util;

/** Issues and validates access tokens; resolves the authenticated user. */
final class Auth
{
    private static ?array $user = null;

    public static function issueToken(Db $db, array $user, Request $request): array
    {
        $jti = Util::uuid4();
        $expiresAt = time() + Jwt::ttl();

        $db->run(
            'INSERT INTO sessions (jti, user_id, user_agent, ip_address, expires_at)
             VALUES (?,?,?,?,to_timestamp(?))',
            [$jti, $user['id'], $request->userAgent(), $request->ip(), $expiresAt]
        );

        $token = Jwt::encode([
            'sub' => (int) $user['id'],
            'eml' => $user['email'],
            'jti' => $jti,
            'iat' => time(),
            'exp' => $expiresAt,
        ]);

        return ['token' => $token, 'expires_at' => date('c', $expiresAt)];
    }

    /** @throws ApiException when the token is missing, invalid or revoked */
    public static function requireUser(Db $db, Request $request): array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $token = $request->bearerToken();
        if ($token === '') {
            throw ApiException::unauthorized('Missing access token');
        }

        $claims = Jwt::decode($token);
        if ($claims === null) {
            throw ApiException::unauthorized('Invalid or expired token');
        }

        $session = $db->one(
            'SELECT id FROM sessions WHERE jti = ? AND NOT revoked AND expires_at > NOW()',
            [$claims['jti'] ?? '']
        );
        if (!$session) {
            throw ApiException::unauthorized('Session has been revoked');
        }

        $user = $db->one(
            'SELECT id, email, full_name, avatar_color, quota_bytes, used_bytes, created_at, last_login
             FROM users WHERE id = ? AND is_active',
            [(int) ($claims['sub'] ?? 0)]
        );
        if (!$user) {
            throw ApiException::unauthorized('Account is not available');
        }

        $db->run('UPDATE sessions SET last_seen = NOW() WHERE id = ?', [$session['id']]);
        $user['session_jti'] = $claims['jti'];

        return self::$user = $user;
    }

    public static function revoke(Db $db, string $jti): void
    {
        $db->run('UPDATE sessions SET revoked = TRUE WHERE jti = ?', [$jti]);
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
