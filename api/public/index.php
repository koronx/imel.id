<?php
/**
 * imel.id REST API — front controller.
 * Every route lives here; controllers hold the behaviour.
 */

declare(strict_types=1);

require __DIR__ . '/../../shared/php/autoload.php';
\Imel\autoload_prefix('Imel\\Api\\', __DIR__ . '/../src');

use Imel\Api\ApiException;
use Imel\Api\Auth;
use Imel\Api\Controllers\AttachmentController;
use Imel\Api\Controllers\AuthController;
use Imel\Api\Controllers\ContactController;
use Imel\Api\Controllers\LabelController;
use Imel\Api\Controllers\MessageController;
use Imel\Api\Controllers\SettingsController;
use Imel\Api\Controllers\ThreadController;
use Imel\Api\Http\Request;
use Imel\Api\Http\Response;
use Imel\Api\Http\Router;
use Imel\Shared\Db;

mb_internal_encoding('UTF-8');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(array_map('trim', explode(',', getenv('CORS_ORIGINS') ?: '*')));
if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$request = new Request();

try {
    $db = Db::get();
} catch (Throwable $e) {
    Response::error('Database tidak tersedia: ' . $e->getMessage(), 503);
    exit;
}

$auth        = new AuthController($db);
$labels      = new LabelController($db);
$threads     = new ThreadController($db);
$messages    = new MessageController($db);
$attachments = new AttachmentController($db);
$contacts    = new ContactController($db);
$settings    = new SettingsController($db);

$router = new Router();

// ---------------------------------------------------------------- public
$router->get('/health', function () use ($db): void {
    Response::ok([
        'status'  => 'ok',
        'service' => 'imel.id api',
        'time'    => date('c'),
        'users'   => (int) $db->value('SELECT COUNT(*) FROM users'),
    ]);
}, false);

$router->post('/auth/register', [$auth, 'register'], false);
$router->post('/auth/login', [$auth, 'login'], false);

// --------------------------------------------------------------- account
$router->post('/auth/logout', [$auth, 'logout']);
$router->get('/auth/me', [$auth, 'me']);
$router->put('/auth/profile', [$auth, 'updateProfile']);
$router->put('/auth/password', [$auth, 'changePassword']);
$router->get('/auth/sessions', [$auth, 'sessions']);
$router->delete('/auth/sessions/{jti}', [$auth, 'revokeSession']);

// ---------------------------------------------------------------- labels
$router->get('/labels', [$labels, 'index']);
$router->post('/labels', [$labels, 'store']);
$router->put('/labels/{id}', [$labels, 'update']);
$router->delete('/labels/{id}', [$labels, 'destroy']);

// --------------------------------------------------------------- threads
$router->get('/threads', [$threads, 'index']);
$router->get('/threads/{key}', [$threads, 'show']);

// -------------------------------------------------------------- messages
$router->get('/messages/{id}', [$messages, 'show']);
$router->get('/messages/{id}/raw', [$messages, 'raw']);
$router->get('/messages/{id}/reply-context', [$messages, 'replyContext']);
$router->post('/messages/batch', [$messages, 'batch']);
$router->post('/messages/send', [$messages, 'send']);

// ---------------------------------------------------------------- drafts
$router->post('/drafts', [$messages, 'saveDraft']);
$router->delete('/drafts/{id}', [$messages, 'destroyDraft']);

// ----------------------------------------------------------- attachments
$router->post('/attachments', [$attachments, 'store']);
$router->get('/attachments/{id}', [$attachments, 'download']);
$router->delete('/attachments/{id}', [$attachments, 'destroy']);

// ------------------------------------------------------ contacts + prefs
$router->get('/contacts', [$contacts, 'index']);
$router->get('/settings', [$settings, 'show']);
$router->put('/settings', [$settings, 'update']);

try {
    $route = $router->match($request);
    if ($route === null) {
        Response::error('Endpoint tidak ditemukan: ' . $request->path(), 404);
        exit;
    }

    if ($route['auth']) {
        Auth::requireUser($db, $request);
    }

    ($route['handler'])($request);
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->status(), $e->extra());
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
    Response::error(
        $debug ? $e->getMessage() : 'Terjadi kesalahan pada server',
        500,
        $debug ? ['file' => $e->getFile(), 'line' => $e->getLine()] : []
    );
}
