<?php
/**
 * imel.id mail server.
 *
 *   SMTP  :25   inbound mail from other servers
 *   SMTP  :587  authenticated submission from mail clients
 *   IMAP  :143  mail access for desktop/mobile clients
 *   queue       delivers everything addressed outside our domain directly to MX
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../../shared/php/autoload.php';
\Imel\autoload_prefix('Imel\\MailServer\\', __DIR__ . '/lib');

use Imel\MailServer\ImapSession;
use Imel\MailServer\QueueWorker;
use Imel\MailServer\SmtpSession;
use Imel\Shared\Util;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

mb_internal_encoding('UTF-8');
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Jakarta');

TcpConnection::$defaultMaxPackageSize = 30 * 1024 * 1024;   // 30 MB message ceiling

$workerCount = (int) (getenv('WORKER_COUNT') ?: 2);

/**
 * Sessions live in a per-worker map keyed by connection id rather than on the
 * connection object, so no dynamic properties are created on Workerman classes.
 */
$makeHandlers = static function (Worker $worker, callable $factory): void {
    $sessions = [];

    $worker->onConnect = static function (TcpConnection $connection) use (&$sessions, $factory): void {
        $session = $factory();
        $sessions[$connection->id] = $session;
        $connection->send($session->greeting());
    };

    $worker->onMessage = static function (TcpConnection $connection, $chunk) use (&$sessions): void {
        $session = $sessions[$connection->id] ?? null;
        if ($session === null) {
            return;
        }
        foreach ($session->feed((string) $chunk) as $reply) {
            $connection->send($reply);
        }
        if ($session->shouldClose()) {
            $connection->close();
        }
    };

    $worker->onClose = static function (TcpConnection $connection) use (&$sessions): void {
        unset($sessions[$connection->id]);
    };

    $worker->onError = static function (TcpConnection $connection, $code, $message): void {
        Util::log('net', "connection error {$code}: {$message}");
    };
};

// ------------------------------------------------------------------ SMTP
foreach ([25, 587] as $port) {
    $smtp = new Worker("tcp://0.0.0.0:{$port}");
    $smtp->name = "SMTP:{$port}";
    $smtp->count = $workerCount;
    $submission = $port === 587;

    $smtp->onWorkerStart = static function () use ($smtp, $makeHandlers, $submission): void {
        $makeHandlers($smtp, static fn() => new SmtpSession($submission));
    };
}

// ------------------------------------------------------------------ IMAP
$imap = new Worker('tcp://0.0.0.0:143');
$imap->name = 'IMAP:143';
$imap->count = $workerCount;

$imap->onWorkerStart = static function () use ($imap, $makeHandlers): void {
    $makeHandlers($imap, static fn() => new ImapSession());
};

// --------------------------------------------------------- outbound relay
$queue = new Worker();
$queue->name = 'Queue';
$queue->count = 1;

$queue->onWorkerStart = static function (): void {
    $worker = new QueueWorker();
    Util::log('queue', 'relay worker started');
    Timer::add(15, static function () use ($worker): void {
        $worker->tick();
    });
};

Util::log('boot', 'imel.id mail server starting for domain ' . Util::mailDomain());

Worker::runAll();
