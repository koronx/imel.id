# Domain Validation Fix

## Masalah
Email untuk domain yang bukan milik imel.id (contoh: admin@pamjaki.online) tetap diterima oleh server, meskipun domain tersebut hanya kebetulan mengarah ke IP yang sama dengan imel.id.

## Solusi
Menambahkan validasi domain dan user pada SMTP server untuk memastikan hanya email untuk domain @imel.id yang diterima, dan hanya untuk user yang terdaftar di database.

## Perubahan yang Dilakukan

### 1. File: `mailserver/src/server.php`

#### Fungsi Helper Baru:
- `getDomainFromEmail($email)` - Mengekstrak domain dari alamat email
- `isLocalDomain($domain)` - Memeriksa apakah domain adalah domain lokal (imel.id)
- `userExists($email)` - Memeriksa apakah user terdaftar di database

#### Validasi RCPT TO Command:
Sebelum:
```php
case 'RCPT':
    if (preg_match('/TO:<(.+?)>/i', $data, $matches)) {
        $connection->smtp_to[] = $matches[1];
        debugLog("[SMTP] RCPT TO", $matches[1]);
        $connection->send("250 OK\r\n");
        $connection->smtp_state = 'RCPT';
    }
```

Sesudah:
```php
case 'RCPT':
    if (preg_match('/TO:<(.+?)>/i', $data, $matches)) {
        $recipient = $matches[1];
        $domain = getDomainFromEmail($recipient);
        
        // Check if domain is local (imel.id)
        if (!isLocalDomain($domain)) {
            debugLog("[SMTP] RCPT TO rejected - domain not served here", ['recipient' => $recipient, 'domain' => $domain]);
            $connection->send("550 Relay not permitted: This server only accepts mail for imel.id\r\n");
            break;
        }
        
        // Check if user exists in database
        if (!userExists($recipient)) {
            debugLog("[SMTP] RCPT TO rejected - user not found", ['recipient' => $recipient]);
            $connection->send("550 User not found: Account does not exist on this server\r\n");
            break;
        }
        
        $connection->smtp_to[] = $recipient;
        debugLog("[SMTP] RCPT TO accepted", $recipient);
        $connection->send("250 OK\r\n");
        $connection->smtp_state = 'RCPT';
    }
```

### 2. File: `mailserver/src/worker.php`

#### Update fungsi `saveLocalEmail()`:
Menambahkan log error yang jelas ketika user tidak ditemukan:

```php
if (!$user) {
    debugLog("[WORKER] Email rejected - user not found", ['recipient' => $recipient]);
    echo "[ERROR] Cannot deliver email to $recipient: Account does not exist on this server\n";
    return false;
}
```

## Dampak Perubahan

### Sebelum Fix:
- Email ke admin@pamjaki.online akan diterima oleh server
- Server akan mencoba memproses email tersebut
- Email akan gagal di-deliver tanpa error yang jelas

### Setelah Fix:
- Email ke admin@pamjaki.online akan langsung ditolak saat RCPT TO command
- Server merespons dengan: `550 Relay not permitted: This server only accepts mail for imel.id`
- Email ke user@imel.id yang tidak terdaftar akan ditolak dengan: `550 User not found: Account does not exist on this server`
- Log error yang jelas akan muncul di worker untuk debugging

## Testing

### Test 1: Email ke domain non-imel.id
```bash
telnet localhost 25
HELO test
MAIL FROM:<sender@example.com>
RCPT TO:<admin@pamjaki.online>
# Expected: 550 Relay not permitted: This server only accepts mail for imel.id
```

### Test 2: Email ke user yang tidak ada
```bash
telnet localhost 25
HELO test
MAIL FROM:<sender@example.com>
RCPT TO:<nonexistent@imel.id>
# Expected: 550 User not found: Account does not exist on this server
```

### Test 3: Email ke user yang valid
```bash
telnet localhost 25
HELO test
MAIL FROM:<sender@example.com>
RCPT TO:<admin@imel.id>
# Expected: 250 OK (jika admin@imel.id terdaftar di database)
```

## Keamanan
- Mencegah server menjadi open relay
- Hanya menerima email untuk domain yang dikelola
- Validasi user sebelum menerima email
- Memberikan feedback error yang jelas untuk troubleshooting

## Deployment
Setelah perubahan di-deploy, restart mail server:
```bash
docker-compose restart mailserver
```
