<?php

namespace Imel\Api\Controllers;

use Imel\Api\ApiException;
use Imel\Api\Auth;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Shared\Db;
use Imel\Shared\Util;

final class AuthController
{
    private const AVATAR_COLORS = ['#1a73e8', '#d93025', '#188038', '#e37400', '#673ab7', '#0b8043', '#c5221f'];

    public function __construct(private Db $db)
    {
    }

    public function register(Request $request): void
    {
        $email    = strtolower($request->string('email'));
        $password = (string) $request->input('password', '');
        $fullName = $request->string('full_name');
        $domain   = Util::mailDomain();

        $errors = [];
        if (!Util::validEmail($email)) {
            $errors['email'] = 'Format email tidak valid';
        } elseif (!str_ends_with($email, '@' . $domain)) {
            $errors['email'] = "Email harus menggunakan domain @{$domain}";
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password minimal 8 karakter';
        }
        if ($fullName === '') {
            $errors['full_name'] = 'Nama lengkap wajib diisi';
        }
        if ($errors) {
            throw ApiException::validation('Data pendaftaran belum lengkap', $errors);
        }

        if ($this->db->one('SELECT id FROM users WHERE lower(email) = ?', [$email])) {
            throw ApiException::validation('Email sudah terdaftar', ['email' => 'Email sudah digunakan']);
        }

        $userId = (int) $this->db->insert(
            'INSERT INTO users (email, password, full_name, avatar_color) VALUES (?,?,?,?) RETURNING id',
            [$email, Auth::hash($password), $fullName, self::AVATAR_COLORS[random_int(0, count(self::AVATAR_COLORS) - 1)]]
        );
        $this->db->run('SELECT bootstrap_user(?)', [$userId]);

        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        $token = Auth::issueToken($this->db, $user, $request);

        Response::json(['user' => $this->publicUser($user), 'auth' => $token], 201);
    }

    public function login(Request $request): void
    {
        $email    = strtolower($request->string('email'));
        $password = (string) $request->input('password', '');

        $user = $this->db->one('SELECT * FROM users WHERE lower(email) = ?', [$email]);
        if (!$user || !Auth::verify($password, $user['password'])) {
            throw ApiException::unauthorized('Email atau password salah');
        }
        if (!$user['is_active']) {
            throw ApiException::unauthorized('Akun dinonaktifkan');
        }

        $this->db->run('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
        $token = Auth::issueToken($this->db, $user, $request);

        Response::ok(['user' => $this->publicUser($user), 'auth' => $token]);
    }

    public function logout(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        Auth::revoke($this->db, $user['session_jti']);
        Response::ok(['ok' => true]);
    }

    public function me(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $settings = $this->db->one('SELECT * FROM user_settings WHERE user_id = ?', [$user['id']]) ?? [];

        Response::ok([
            'user'     => $this->publicUser($user),
            'settings' => SettingsController::normalize($settings),
        ]);
    }

    public function changePassword(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $current = (string) $request->input('current_password', '');
        $next    = (string) $request->input('new_password', '');

        $stored = (string) $this->db->value('SELECT password FROM users WHERE id = ?', [$user['id']]);
        if (!Auth::verify($current, $stored)) {
            throw ApiException::validation('Password saat ini salah', ['current_password' => 'Password salah']);
        }
        if (strlen($next) < 8) {
            throw ApiException::validation('Password baru minimal 8 karakter', ['new_password' => 'Minimal 8 karakter']);
        }

        $this->db->run('UPDATE users SET password = ? WHERE id = ?', [Auth::hash($next), $user['id']]);
        // every other device must sign in again
        $this->db->run('UPDATE sessions SET revoked = TRUE WHERE user_id = ? AND jti <> ?', [$user['id'], $user['session_jti']]);

        Response::ok(['ok' => true]);
    }

    public function updateProfile(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $fullName = $request->string('full_name', $user['full_name']);
        $color = $request->string('avatar_color', $user['avatar_color']);

        if ($fullName === '') {
            throw ApiException::validation('Nama tidak boleh kosong', ['full_name' => 'Wajib diisi']);
        }
        if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            $color = $user['avatar_color'];
        }

        $this->db->run('UPDATE users SET full_name = ?, avatar_color = ? WHERE id = ?', [$fullName, $color, $user['id']]);
        $fresh = $this->db->one('SELECT * FROM users WHERE id = ?', [$user['id']]);

        Response::ok(['user' => $this->publicUser($fresh)]);
    }

    public function sessions(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $rows = $this->db->all(
            'SELECT jti, user_agent, ip_address, created_at, last_seen, expires_at
             FROM sessions WHERE user_id = ? AND NOT revoked AND expires_at > NOW()
             ORDER BY last_seen DESC',
            [$user['id']]
        );

        foreach ($rows as &$row) {
            $row['current'] = $row['jti'] === $user['session_jti'];
        }

        Response::ok(['sessions' => $rows]);
    }

    public function revokeSession(Request $request): void
    {
        $user = Auth::requireUser($this->db, $request);
        $jti = (string) ($request->params['jti'] ?? '');

        $owned = $this->db->one('SELECT id FROM sessions WHERE jti = ? AND user_id = ?', [$jti, $user['id']]);
        if (!$owned) {
            throw ApiException::notFound('Sesi tidak ditemukan');
        }

        Auth::revoke($this->db, $jti);
        Response::ok(['ok' => true]);
    }

    private function publicUser(array $user): array
    {
        return [
            'id'           => (int) $user['id'],
            'email'        => $user['email'],
            'full_name'    => $user['full_name'],
            'avatar_color' => $user['avatar_color'],
            'quota_bytes'  => (int) $user['quota_bytes'],
            'used_bytes'   => (int) $user['used_bytes'],
            'created_at'   => $user['created_at'] ?? null,
            'last_login'   => $user['last_login'] ?? null,
        ];
    }
}
