# Testing Guide - Aplikasi Mobile imel.id

## Status Saat Ini ✅

Email dari `test@imel.id` ke `koronx@imel.id` **SUDAH TERKIRIM DAN TERSIMPAN** di database.

## Cara Melihat Email di Aplikasi Mobile

### 1. Pastikan Aplikasi Flutter Berjalan
```bash
cd mobile
flutter run -d chrome
```

### 2. Login sebagai Penerima Email

**Akun Koronx (Penerima):**
- Email: `koronx@imel.id`
- Password: `password123`

**Akun Test (Pengirim):**
- Email: `test@imel.id`
- Password: `password123`

**Akun Admin:**
- Email: `admin@imel.id`
- Password: `password123`

### 3. Langkah Testing

#### A. Test Melihat Email yang Diterima
1. Buka aplikasi Flutter di Chrome
2. Jika sudah login, klik **icon profil** > **Keluar**
3. Login dengan `koronx@imel.id` / `password123`
4. Lihat **Kotak Masuk** - seharusnya ada email dari test@imel.id
5. Klik email untuk melihat detail

#### B. Test Mengirim Email
1. Login sebagai `test@imel.id`
2. Klik tombol **Tulis Email** (icon pensil di kanan bawah)
3. Isi form:
   - **Kepada**: `koronx@imel.id`
   - **Subjek**: `Test dari Flutter App`
   - **Pesan**: `Ini adalah test email`
4. Klik **icon kirim** di kanan atas
5. Logout dan login sebagai `koronx@imel.id`
6. Email baru akan muncul di inbox

### 4. Troubleshooting

#### Email Tidak Muncul?
1. **Refresh Inbox**: Klik icon refresh di kanan atas
2. **Clear Cache**: 
   - Di Chrome: F12 > Application > Clear storage
   - Refresh halaman (F5)
3. **Cek Console**: F12 > Console untuk melihat error

#### Error Network?
```bash
# Pastikan backend running
docker compose ps

# Restart jika perlu
docker compose restart webmail
```

#### Test API Langsung
```bash
# Login
curl -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"koronx@imel.id","password":"password123"}'

# Get Inbox (ganti TOKEN dengan token dari login)
curl -X GET "http://localhost/api.php?action=get_inbox" \
  -H "Authorization: Bearer TOKEN"
```

## Verifikasi Email di Database

```bash
# Cek semua email untuk koronx
docker exec -it maildb psql -U mailuser -d maildb -c \
  "SELECT id, from_email, subject, received_at 
   FROM emails 
   WHERE to_email = 'koronx@imel.id' 
   ORDER BY received_at DESC 
   LIMIT 10;"

# Cek email tertentu
docker exec -it maildb psql -U mailuser -d maildb -c \
  "SELECT * FROM emails 
   WHERE from_email = 'test@imel.id' 
   AND to_email = 'koronx@imel.id';"
```

## Fitur yang Tersedia

✅ Login / Register
✅ Lihat Inbox
✅ Lihat Sent Emails
✅ Kirim Email
✅ Baca Email Detail
✅ Tandai Sudah Dibaca
✅ Hapus Email
✅ Logout

## URL Aplikasi

- **Web App**: http://localhost (webmail)
- **API**: http://localhost/api.php
- **Flutter App**: http://localhost:PORT (otomatis dari `flutter run`)

## Tips

1. **Gunakan Tab Terkirim**: Untuk melihat email yang Anda kirim
2. **Refresh Otomatis**: Setelah kirim email, inbox akan auto-refresh
3. **Multiple Login**: Buka 2 tab Chrome untuk login sebagai 2 user berbeda
4. **Debug Mode**: Cek F12 > Console untuk melihat request/response API
