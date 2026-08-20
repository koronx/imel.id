<?php

namespace Imel\MailServer;

use Imel\Shared\Db;
use Imel\Shared\Mime\Builder;
use Imel\Shared\Storage;
use Imel\Shared\Util;
use Throwable;

/**
 * IMAP4rev1 subset good enough for desktop and mobile clients to read mail:
 * CAPABILITY, LOGIN, LIST/LSUB, SELECT/EXAMINE, STATUS, SEARCH, FETCH, STORE,
 * EXPUNGE, CLOSE and LOGOUT — including the UID variants of FETCH/STORE/SEARCH.
 */
final class ImapSession
{
    private const MAILBOXES = [
        'INBOX'   => 'inbox',
        'Sent'    => 'sent',
        'Drafts'  => 'drafts',
        'Trash'   => 'trash',
        'Spam'    => 'spam',
        'Archive' => 'archive',
    ];

    private string $buffer = '';
    private bool $authenticated = false;
    private bool $close = false;
    private ?array $user = null;
    private string $folder = '';
    private bool $readOnly = false;
    /** @var array<int,array> sequence number => message row */
    private array $view = [];

    public function greeting(): string
    {
        return "* OK [CAPABILITY IMAP4rev1 AUTH=PLAIN] imel.id IMAP ready\r\n";
    }

    public function shouldClose(): bool
    {
        return $this->close;
    }

    /** @return string[] */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $replies = [];

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r\n");
            $this->buffer = substr($this->buffer, $pos + 1);

            if (trim($line) === '') {
                continue;
            }
            foreach ($this->command($line) as $reply) {
                $replies[] = $reply;
            }
        }

        return $replies;
    }

    /** @return string[] */
    private function command(string $line): array
    {
        if (!preg_match('/^(\S+)\s+([A-Za-z]+)\s*(.*)$/', trim($line), $m)) {
            return ["* BAD Invalid command syntax\r\n"];
        }

        [$tag, $command, $args] = [$m[1], strtoupper($m[2]), trim($m[3])];

        if ($command === 'UID') {
            if (!preg_match('/^([A-Za-z]+)\s*(.*)$/', $args, $u)) {
                return ["{$tag} BAD Invalid UID command\r\n"];
            }
            return $this->dispatch($tag, strtoupper($u[1]), trim($u[2]), true);
        }

        return $this->dispatch($tag, $command, $args, false);
    }

    /** @return string[] */
    private function dispatch(string $tag, string $command, string $args, bool $uid): array
    {
        return match ($command) {
            'CAPABILITY' => ["* CAPABILITY IMAP4rev1 UIDPLUS\r\n", "{$tag} OK CAPABILITY completed\r\n"],
            'NOOP'       => ["{$tag} OK NOOP completed\r\n"],
            'LOGIN'      => $this->login($tag, $args),
            'LOGOUT'     => $this->logout($tag),
            'LIST',
            'LSUB'       => $this->list($tag, $command),
            'SELECT'     => $this->select($tag, $args, false),
            'EXAMINE'    => $this->select($tag, $args, true),
            'STATUS'     => $this->status($tag, $args),
            'SEARCH'     => $this->search($tag, $args, $uid),
            'FETCH'      => $this->fetch($tag, $args, $uid),
            'STORE'      => $this->store($tag, $args, $uid),
            'EXPUNGE'    => $this->expunge($tag),
            'CLOSE'      => $this->closeMailbox($tag),
            'CREATE',
            'DELETE',
            'SUBSCRIBE',
            'UNSUBSCRIBE' => ["{$tag} OK {$command} completed\r\n"],
            default      => ["{$tag} BAD Command {$command} not supported\r\n"],
        };
    }

    private function login(string $tag, string $args): array
    {
        if (!preg_match('/^"?([^"\s]+)"?\s+"?(.+?)"?$/', $args, $m)) {
            return ["{$tag} BAD LOGIN syntax error\r\n"];
        }

        try {
            $row = Db::get()->one(
                'SELECT id, email, full_name, password FROM users WHERE lower(email) = lower(?) AND is_active',
                [$m[1]]
            );
        } catch (Throwable $e) {
            Db::reset();
            return ["{$tag} NO Temporary authentication failure\r\n"];
        }

        if (!$row || !password_verify($m[2], $row['password'])) {
            Util::log('imap', "login failed for {$m[1]}");
            return ["{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n"];
        }

        $this->user = $row;
        $this->authenticated = true;
        Util::log('imap', "login ok for {$row['email']}");

        return ["{$tag} OK [CAPABILITY IMAP4rev1 UIDPLUS] LOGIN completed\r\n"];
    }

    private function logout(string $tag): array
    {
        $this->close = true;
        return ["* BYE imel.id IMAP signing off\r\n", "{$tag} OK LOGOUT completed\r\n"];
    }

    private function list(string $tag, string $command): array
    {
        if (!$this->authenticated) {
            return ["{$tag} NO Not authenticated\r\n"];
        }

        $out = [];
        foreach (array_keys(self::MAILBOXES) as $mailbox) {
            $attributes = match ($mailbox) {
                'Sent'    => '\HasNoChildren \Sent',
                'Drafts'  => '\HasNoChildren \Drafts',
                'Trash'   => '\HasNoChildren \Trash',
                'Spam'    => '\HasNoChildren \Junk',
                'Archive' => '\HasNoChildren \Archive',
                default   => '\HasNoChildren',
            };
            $out[] = "* {$command} ({$attributes}) \"/\" \"{$mailbox}\"\r\n";
        }
        $out[] = "{$tag} OK {$command} completed\r\n";

        return $out;
    }

    private function select(string $tag, string $args, bool $readOnly): array
    {
        if (!$this->authenticated) {
            return ["{$tag} NO Not authenticated\r\n"];
        }

        $mailbox = trim($args, "\" \t");
        $folder = $this->folderFor($mailbox);
        if ($folder === null) {
            return ["{$tag} NO [NONEXISTENT] Mailbox does not exist\r\n"];
        }

        $this->folder = $folder;
        $this->readOnly = $readOnly;
        $this->loadView();

        $count = count($this->view);
        $unseen = 0;
        $uidNext = 1;
        foreach ($this->view as $row) {
            if (!$this->pgBool($row['is_read'])) {
                $unseen++;
            }
            $uidNext = max($uidNext, (int) $row['id'] + 1);
        }

        return [
            "* {$count} EXISTS\r\n",
            "* 0 RECENT\r\n",
            "* OK [UNSEEN {$unseen}] Unseen messages\r\n",
            "* OK [UIDVALIDITY 1] UIDs valid\r\n",
            "* OK [UIDNEXT {$uidNext}] Predicted next UID\r\n",
            "* FLAGS (\\Seen \\Answered \\Flagged \\Deleted \\Draft)\r\n",
            "* OK [PERMANENTFLAGS (\\Seen \\Flagged \\Deleted \\Draft)] Limited\r\n",
            sprintf("%s OK [%s] %s completed\r\n", $tag, $readOnly ? 'READ-ONLY' : 'READ-WRITE', $readOnly ? 'EXAMINE' : 'SELECT'),
        ];
    }

    private function status(string $tag, string $args): array
    {
        if (!$this->authenticated) {
            return ["{$tag} NO Not authenticated\r\n"];
        }
        if (!preg_match('/^"?([^"\s]+)"?\s*\((.*)\)$/', trim($args), $m)) {
            return ["{$tag} BAD STATUS syntax error\r\n"];
        }

        $folder = $this->folderFor(trim($m[1], '" '));
        if ($folder === null) {
            return ["{$tag} NO Mailbox does not exist\r\n"];
        }

        $row = Db::get()->one(
            'SELECT COUNT(*) AS messages, COUNT(*) FILTER (WHERE NOT is_read) AS unseen,
                    COALESCE(MAX(id), 0) + 1 AS uidnext
             FROM messages WHERE user_id = ? AND folder = ?',
            [$this->user['id'], $folder]
        );

        $items = [];
        foreach (preg_split('/\s+/', strtoupper(trim($m[2]))) ?: [] as $item) {
            $items[] = match ($item) {
                'MESSAGES'    => 'MESSAGES ' . (int) $row['messages'],
                'UNSEEN'      => 'UNSEEN ' . (int) $row['unseen'],
                'RECENT'      => 'RECENT 0',
                'UIDNEXT'     => 'UIDNEXT ' . (int) $row['uidnext'],
                'UIDVALIDITY' => 'UIDVALIDITY 1',
                default       => null,
            };
        }
        $items = array_filter($items);

        return [
            '* STATUS "' . trim($m[1], '" ') . '" (' . implode(' ', $items) . ")\r\n",
            "{$tag} OK STATUS completed\r\n",
        ];
    }

    private function search(string $tag, string $args, bool $uid): array
    {
        if ($this->folder === '') {
            return ["{$tag} NO No mailbox selected\r\n"];
        }

        $criteria = strtoupper(trim($args));
        $hits = [];

        foreach ($this->view as $sequence => $row) {
            $match = match (true) {
                $criteria === '' || $criteria === 'ALL' => true,
                str_contains($criteria, 'UNSEEN')       => !$this->pgBool($row['is_read']),
                str_contains($criteria, 'SEEN')         => $this->pgBool($row['is_read']),
                str_contains($criteria, 'FLAGGED')      => $this->pgBool($row['is_starred']),
                str_contains($criteria, 'NEW')          => !$this->pgBool($row['is_read']),
                default                                 => $this->matchText($row, $criteria),
            };
            if ($match) {
                $hits[] = $uid ? (int) $row['id'] : $sequence;
            }
        }

        return [
            '* SEARCH' . ($hits ? ' ' . implode(' ', $hits) : '') . "\r\n",
            "{$tag} OK SEARCH completed\r\n",
        ];
    }

    private function fetch(string $tag, string $args, bool $uid): array
    {
        if ($this->folder === '') {
            return ["{$tag} NO No mailbox selected\r\n"];
        }
        if (!preg_match('/^(\S+)\s+(.*)$/s', trim($args), $m)) {
            return ["{$tag} BAD FETCH syntax error\r\n"];
        }

        $items = strtoupper($m[2]);
        $out = [];

        foreach ($this->resolveSet($m[1], $uid) as $sequence => $row) {
            $parts = [];

            if (str_contains($items, 'UID') || $uid) {
                $parts[] = 'UID ' . (int) $row['id'];
            }
            if (str_contains($items, 'FLAGS') || str_contains($items, 'ALL') || str_contains($items, 'FAST')) {
                $parts[] = 'FLAGS (' . $this->flags($row) . ')';
            }
            if (str_contains($items, 'INTERNALDATE') || str_contains($items, 'ALL') || str_contains($items, 'FAST')) {
                $parts[] = 'INTERNALDATE "' . date('d-M-Y H:i:s O', strtotime((string) $row['internal_date']) ?: time()) . '"';
            }
            if (str_contains($items, 'RFC822.SIZE') || str_contains($items, 'ALL') || str_contains($items, 'FAST')) {
                $parts[] = 'RFC822.SIZE ' . strlen($this->rawFor($row));
            }
            if (str_contains($items, 'ENVELOPE') || str_contains($items, 'ALL')) {
                $parts[] = 'ENVELOPE ' . $this->envelope($row);
            }

            $literal = null;
            if (preg_match('/BODY(?:\.PEEK)?\[([^\]]*)\]/', $items, $b)) {
                $section = strtoupper(trim($b[1]));
                $raw = $this->rawFor($row);
                $body = match (true) {
                    $section === ''        => $raw,
                    $section === 'HEADER'  => explode("\r\n\r\n", $raw, 2)[0] . "\r\n\r\n",
                    $section === 'TEXT'    => explode("\r\n\r\n", $raw, 2)[1] ?? '',
                    str_starts_with($section, 'HEADER.FIELDS') => $this->headerFields($raw, $section),
                    default                => $raw,
                };
                $literal = ['label' => 'BODY[' . trim($b[1]) . ']', 'data' => $body];
            } elseif (str_contains($items, 'RFC822')) {
                $literal = ['label' => 'RFC822', 'data' => $this->rawFor($row)];
            }

            if ($literal !== null) {
                $out[] = sprintf(
                    "* %d FETCH (%s%s {%d}\r\n%s)\r\n",
                    $sequence,
                    $parts ? implode(' ', $parts) . ' ' : '',
                    $literal['label'],
                    strlen($literal['data']),
                    $literal['data']
                );
            } else {
                $out[] = sprintf("* %d FETCH (%s)\r\n", $sequence, implode(' ', $parts));
            }

            // BODY[...] without .PEEK implicitly marks the message as read
            if (!$this->readOnly && preg_match('/BODY\[/', $items) && !str_contains($items, 'PEEK')) {
                Db::get()->run('UPDATE messages SET is_read = TRUE WHERE id = ?', [$row['id']]);
            }
        }

        $out[] = "{$tag} OK FETCH completed\r\n";

        return $out;
    }

    private function store(string $tag, string $args, bool $uid): array
    {
        if ($this->folder === '' || $this->readOnly) {
            return ["{$tag} NO Mailbox is read-only\r\n"];
        }
        if (!preg_match('/^(\S+)\s+([+-]?)FLAGS(?:\.SILENT)?\s*\((.*)\)$/i', trim($args), $m)) {
            return ["{$tag} BAD STORE syntax error\r\n"];
        }

        $mode = $m[2];
        $flags = strtolower($m[3]);
        $adding = $mode !== '-';
        $silent = stripos($args, '.SILENT') !== false;
        $db = Db::get();
        $out = [];

        foreach ($this->resolveSet($m[1], $uid) as $sequence => $row) {
            if (str_contains($flags, '\\seen')) {
                $db->run('UPDATE messages SET is_read = ? WHERE id = ?', [$adding ? 'true' : 'false', $row['id']]);
                $this->view[$sequence]['is_read'] = $adding;
            }
            if (str_contains($flags, '\\flagged')) {
                $db->run('UPDATE messages SET is_starred = ? WHERE id = ?', [$adding ? 'true' : 'false', $row['id']]);
                $this->view[$sequence]['is_starred'] = $adding;
            }
            if (str_contains($flags, '\\deleted') && $adding) {
                $db->run("UPDATE messages SET folder = 'trash' WHERE id = ?", [$row['id']]);
            }

            if (!$silent) {
                $out[] = sprintf("* %d FETCH (FLAGS (%s))\r\n", $sequence, $this->flags($this->view[$sequence]));
            }
        }

        $out[] = "{$tag} OK STORE completed\r\n";

        return $out;
    }

    private function expunge(string $tag): array
    {
        if ($this->folder === '' || $this->readOnly) {
            return ["{$tag} NO Mailbox is read-only\r\n"];
        }
        $this->loadView();

        return ["{$tag} OK EXPUNGE completed\r\n"];
    }

    private function closeMailbox(string $tag): array
    {
        $this->folder = '';
        $this->view = [];

        return ["{$tag} OK CLOSE completed\r\n"];
    }

    // ------------------------------------------------------------ helpers

    private function folderFor(string $mailbox): ?string
    {
        foreach (self::MAILBOXES as $name => $folder) {
            if (strcasecmp($name, $mailbox) === 0) {
                return $folder;
            }
        }
        return null;
    }

    private function loadView(): void
    {
        $rows = Db::get()->all(
            'SELECT * FROM messages WHERE user_id = ? AND folder = ? ORDER BY internal_date ASC, id ASC',
            [$this->user['id'], $this->folder]
        );

        $this->view = [];
        foreach ($rows as $index => $row) {
            $this->view[$index + 1] = $row;      // IMAP sequence numbers start at 1
        }
    }

    /** @return array<int,array> sequence => row */
    private function resolveSet(string $set, bool $uid): array
    {
        $selected = [];

        foreach (explode(',', $set) as $range) {
            [$startRaw, $endRaw] = array_pad(explode(':', trim($range), 2), 2, null);
            $start = $startRaw === '*' ? PHP_INT_MAX : (int) $startRaw;
            $end = $endRaw === null ? $start : ($endRaw === '*' ? PHP_INT_MAX : (int) $endRaw);
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            foreach ($this->view as $sequence => $row) {
                $value = $uid ? (int) $row['id'] : $sequence;
                if ($value >= $start && $value <= $end) {
                    $selected[$sequence] = $row;
                } elseif ($start === PHP_INT_MAX && $sequence === count($this->view)) {
                    $selected[$sequence] = $row;
                }
            }
        }

        ksort($selected);

        return $selected;
    }

    private function flags(array $row): string
    {
        $flags = [];
        if ($this->pgBool($row['is_read'])) {
            $flags[] = '\Seen';
        }
        if ($this->pgBool($row['is_starred'])) {
            $flags[] = '\Flagged';
        }
        if ($this->pgBool($row['is_draft'])) {
            $flags[] = '\Draft';
        }
        return implode(' ', $flags);
    }

    /** Raw source when we kept it, otherwise rebuild one from the stored parts. */
    private function rawFor(array $row): string
    {
        $path = (string) $row['raw_path'];
        if ($path !== '' && Storage::isInside($path) && is_file($path)) {
            $raw = (string) file_get_contents($path);
            return str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw);
        }

        return Builder::build([
            'message_id' => $row['message_id'] ?: Util::messageId(),
            'from_email' => $row['from_email'],
            'from_name'  => $row['from_name'],
            'to'         => Util::emailsFromJson($row['to_json']),
            'cc'         => Util::emailsFromJson($row['cc_json']),
            'subject'    => $row['subject'],
            'text'       => $row['body_text'],
            'html'       => $row['body_html'],
            'date'       => strtotime((string) $row['internal_date']) ?: time(),
        ]);
    }

    private function envelope(array $row): string
    {
        $quote = static fn(string $value): string => $value === '' ? 'NIL' : '"' . str_replace('"', '\"', $value) . '"';
        $address = static function (string $email, string $name = '') use ($quote): string {
            [$local, $host] = array_pad(explode('@', $email, 2), 2, '');
            return '((' . $quote($name) . ' NIL ' . $quote($local) . ' ' . $quote($host) . '))';
        };

        $to = Util::emailsFromJson($row['to_json'])[0] ?? '';

        return '('
            . $quote(date('r', strtotime((string) $row['internal_date']) ?: time())) . ' '
            . $quote((string) $row['subject']) . ' '
            . $address((string) $row['from_email'], (string) $row['from_name']) . ' '
            . $address((string) $row['from_email'], (string) $row['from_name']) . ' '
            . $address((string) $row['from_email'], (string) $row['from_name']) . ' '
            . ($to !== '' ? $address($to) : 'NIL') . ' '
            . 'NIL NIL NIL '
            . $quote((string) $row['message_id'])
            . ')';
    }

    private function headerFields(string $raw, string $section): string
    {
        preg_match('/\(([^)]*)\)/', $section, $m);
        $wanted = array_map('strtolower', preg_split('/\s+/', trim($m[1] ?? '')) ?: []);
        $headerBlock = explode("\r\n\r\n", $raw, 2)[0];

        $out = '';
        foreach (explode("\r\n", $headerBlock) as $line) {
            $name = strtolower(trim(explode(':', $line, 2)[0] ?? ''));
            if (in_array($name, $wanted, true)) {
                $out .= $line . "\r\n";
            }
        }

        return $out . "\r\n";
    }

    private function matchText(array $row, string $criteria): bool
    {
        if (!preg_match('/(?:SUBJECT|BODY|TEXT|FROM)\s+"?([^"]+)"?/i', $criteria, $m)) {
            return false;
        }
        $needle = mb_strtolower(trim($m[1]));
        $haystack = mb_strtolower(
            $row['subject'] . ' ' . $row['from_email'] . ' ' . $row['from_name'] . ' ' . $row['body_text']
        );

        return str_contains($haystack, $needle);
    }

    private function pgBool($value): bool
    {
        return $value === true || $value === 't' || $value === 'true' || $value === 1 || $value === '1';
    }
}
