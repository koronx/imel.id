<?php

namespace Imel\Api\Http;

final class Request
{
    public array $params = [];      // route placeholders, e.g. /messages/{id}
    private ?array $jsonCache = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = preg_replace('#^/api#', '', $uri) ?: '/';
        return '/' . trim($uri, '/');
    }

    public function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = $_GET[$key] ?? null;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function queryBool(string $key, bool $default = false): bool
    {
        $value = $_GET[$key] ?? null;
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** JSON body, or the form fields when the request is multipart. */
    public function body(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'multipart/form-data') || str_contains($contentType, 'x-www-form-urlencoded')) {
            return $this->jsonCache = $_POST;
        }

        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return $this->jsonCache = is_array($decoded) ? $decoded : [];
    }

    public function input(string $key, $default = null)
    {
        return $this->body()[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, null);
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /** @return array<int,mixed> */
    public function arrayInput(string $key): array
    {
        $value = $this->input($key, []);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)));
        }
        return is_array($value) ? $value : [];
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public function bearerToken(): string
    {
        $auth = $this->header('Authorization');
        if ($auth === '' && function_exists('apache_request_headers')) {
            $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
            $auth = $headers['authorization'] ?? '';
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        // downloads open in a new tab and cannot carry an Authorization header
        return trim((string) ($_GET['access_token'] ?? ''));
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
    }

    /** Normalized $_FILES entries for one field, single or multiple. */
    public function files(string $field): array
    {
        if (!isset($_FILES[$field])) {
            return [];
        }
        $entry = $_FILES[$field];
        if (!is_array($entry['name'])) {
            return $entry['error'] === UPLOAD_ERR_OK ? [$entry] : [];
        }

        $out = [];
        foreach ($entry['name'] as $i => $name) {
            if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $out[] = [
                'name'     => $name,
                'type'     => $entry['type'][$i] ?? 'application/octet-stream',
                'tmp_name' => $entry['tmp_name'][$i],
                'size'     => $entry['size'][$i] ?? 0,
                'error'    => UPLOAD_ERR_OK,
            ];
        }
        return $out;
    }
}
