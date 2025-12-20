# Fix: Email dari Webmail Tidak Muncul di Mobile

## Masalah
Email yang dikirim dari webmail (koronx@imel.id → test@imel.id) tidak muncul di inbox mobile app.

## Penyebab
Di file `webmail/src/pages/send.php` line 179, saat menyimpan email ke sent folder menggunakan kolom `sent_at`:
```php
INSERT INTO emails (..., folder, sent_at, size) VALUES (?, ?, 'sent', NOW(), ?)
```

Sedangkan API mobile dan query lainnya menggunakan kolom `received_at` untuk sorting dan filtering.

## Solusi
Ubah kolom `sent_at` menjadi `received_at` di send.php:
```php
INSERT INTO emails (..., folder, received_at, size) VALUES (?, ?, 'sent', NOW(), ?)
```

## Verifikasi
Email yang dikirim via webmail sekarang muncul dengan benar di mobile inbox:

### Database
```sql
SELECT id, user_id, folder, from_email, to_email, subject FROM emails 
WHERE from_email = 'koronx@imel.id' AND to_email = 'test@imel.id';

-- Result:
-- id=55: user_id=3, folder='inbox' (test@imel.id inbox)
-- id=56: user_id=2, folder='sent' (koronx@imel.id sent)
```

### Mobile API
```bash
curl 'http://localhost/api.php?action=get_inbox' -H "Authorization: Bearer TOKEN"

# Response includes email from koronx@imel.id
{
  "success": true,
  "emails": [{
    "id": 55,
    "sender": "koronx@imel.id",
    "recipient": "test@imel.id",
    "subject": "test",
    ...
  }]
}
```

## Test Script
Gunakan script `test_webmail_to_mobile.sh` untuk verifikasi flow lengkap:
```bash
./test_webmail_to_mobile.sh
```

Script akan:
1. Cek database untuk email dari koronx ke test
2. Test mobile API login
3. Verifikasi email muncul di inbox mobile
4. Verifikasi email muncul di sent folder
