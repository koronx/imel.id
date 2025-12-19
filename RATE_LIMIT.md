# Fitur Rate Limiting Email Eksternal

## Deskripsi
Fitur ini membatasi pengiriman email ke domain eksternal (non-@imel.id) maksimal 10 email per jam per user untuk mencegah penyalahgunaan sistem email.

Rate limiting diterapkan di 2 tempat:
1. **Webmail** - Saat user mengirim email via interface web
2. **Mail Worker** - Saat worker memproses queue dan mengirim ke domain eksternal

## Implementasi

### 1. Database Schema
Tabel baru `external_email_log` telah ditambahkan untuk melacak pengiriman email eksternal:

```sql
CREATE TABLE external_email_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    to_email VARCHAR(255) NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2. Fungsi Rate Limiting

#### `checkExternalEmailRateLimit($userId, $db)`
- Memeriksa jumlah email eksternal yang dikirim user dalam 1 jam terakhir
- Mengembalikan array dengan informasi:
  - `allowed`: boolean, apakah masih boleh mengirim
  - `current`: jumlah email yang sudah dikirim
  - `limit`: batas maksimal (10)
  - `remaining`: sisa kuota

#### `logExternalEmail($userId, $toEmail, $db)`
- Mencatat setiap pengiriman email eksternal ke database
- Dipanggil setelah email berhasil dimasukkan ke queue

#### Di Mail Worker (`mailserver/src/worker.php`)

#### `checkExternalEmailRateLimit($fromEmail, $db)`
- Memeriksa jumlah email eksternal yang dikirim dari email tertentu dalam 1 jam terakhir
- Jika pengirim bukan user lokal, izinkan (untuk email masuk dari luar)
- Mengembalikan array dengan informasi yang sama seperti fungsi webmail

#### `logExternalEmailFromWorker($fromEmail, $toEmail, $db)`
- Mencatat setiap pengiriman email eksternal yang berhasil
- Dipanggil setelah email berhasil dikirim via SMTP

### 3. Cara Kerja

#### Di Webmail (webmail/src/pages/send.php)
1. Ketika user mengirim email, sistem mengecek apakah tujuan adalah domain internal (@imel.id) atau eksternal
2. Untuk email eksternal, sistem memeriksa rate limit dengan query:
   ```sql
   SELECT COUNT(*) FROM external_email_log 
   WHERE user_id = ? AND sent_at > NOW() - INTERVAL '1 hour'
   ```
3. Jika sudah mencapai batas 10 email per jam, email ditolak dengan pesan error
4. Jika masih di bawah batas, email dimasukkan ke queue dan dicatat ke `external_email_log`

#### Di Mail Worker (mailserver/src/worker.php)
1. Worker mengambil email dari queue Redis
2. Jika tujuan adalah domain eksternal, worker memeriksa rate limit pengirim
3. Jika rate limit tercapai, email tidak dikirim dan dicatat di log
4. Jika masih di bawah batas, email dikirim via SMTP dan dicatat ke `external_email_log`

### 4. Pesan Error
Ketika batas tercapai, user akan menerima pesan:
```
Batas pengiriman email eksternal tercapai. Anda sudah mengirim X dari 10 email per jam. Silakan coba lagi nanti.
```

## Instalasi

### Untuk Database Baru
Tabel sudah termasuk dalam `db/init.sql`, cukup jalankan:
```bash
docker-compose up -d database
```

### Untuk Database yang Sudah Ada
Jalankan migration script:
```bash
docker-compose exec database psql -U mailuser -d maildb -f /db/add_rate_limit.sql
```

Atau manual:
```bash
docker-compose exec database psql -U mailuser -d maildb
```
Kemudian paste isi file `db/add_rate_limit.sql`

## Testing

### Test 1: Kirim Email Eksternal (Berhasil)
1. Login ke webmail
2. Kirim email ke domain eksternal (contoh: test@gmail.com)
3. Email seharusnya berhasil dikirim

### Test 2: Test Rate Limit
1. Kirim 10 email eksternal dalam 1 jam
2. Coba kirim email eksternal ke-11
3. Seharusnya muncul pesan error dan email ditolak

### Test 3: Email Internal Tidak Terbatas
1. Kirim banyak email ke @imel.id
2. Seharusnya tidak ada batasan

### Test 4: Reset Setelah 1 Jam
1. Setelah 1 jam dari email pertama, coba kirim lagi
2. Seharusnya bisa mengirim lagi (email lama tidak dihitung)

## Monitoring

### Cek Jumlah Email User
```sql
SELECT 
    u.email,
    COUNT(*) as total_external_emails,
    MIN(el.sent_at) as first_sent,
    MAX(el.sent_at) as last_sent
FROM users u
LEFT JOIN external_email_log el ON u.id = el.user_id
WHERE el.sent_at > NOW() - INTERVAL '1 hour'
GROUP BY u.id, u.email;
```

### Cleanup Log Lama (Optional)
Untuk membersihkan log yang lebih dari 24 jam:
```sql
DELETE FROM external_email_log 
WHERE sent_at < NOW() - INTERVAL '24 hours';
```

Bisa dijadwalkan dengan cron job atau task scheduler.

## Konfigurasi

Untuk mengubah batas maksimal email per jam, edit nilai `$maxEmailsPerHour` di file `webmail/src/pages/send.php`:

```php
function checkExternalEmailRateLimit($userId, $db) {
    $maxEmailsPerHour = 10; // Ubah nilai ini
    // ... kode lainnya
}
```

## Catatan
- Rate limit hanya berlaku untuk email eksternal (bukan @imel.id)
- Email internal tidak dibatasi
- Rate limit dihitung per user
- Window perhitungan adalah rolling 1 jam (bukan per jam kalender)
