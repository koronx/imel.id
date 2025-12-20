# Setup Google OAuth Login

## Langkah-langkah Setup:

### 1. Buat Google Cloud Project
1. Buka [Google Cloud Console](https://console.cloud.google.com/)
2. Buat project baru atau pilih project yang ada
3. Aktifkan API: **Google+ API** atau **People API**

### 2. Buat OAuth 2.0 Credentials
1. Buka **APIs & Services** > **Credentials**
2. Klik **+ CREATE CREDENTIALS** > **OAuth 2.0 Client ID**
3. Pilih **Application type**: **Web application**
4. Isi **Name**: `imel.id Webmail`
5. Tambahkan **Authorized redirect URIs**:
   - Development: `http://localhost/oauth-callback`
   - Production: `https://your-domain.com/oauth-callback` atau `http://your-ip/oauth-callback`
6. Klik **CREATE**
7. Copy **Client ID** dan **Client Secret**

### 3. Konfigurasi Environment Variables

#### Untuk Development (localhost):
```bash
export GOOGLE_CLIENT_ID="your-client-id.apps.googleusercontent.com"
export GOOGLE_CLIENT_SECRET="your-client-secret"
export GOOGLE_REDIRECT_URI="http://localhost/oauth-callback"
```

#### Untuk Production:
Tambahkan di `docker-compose.yml` atau `.env`:
```yaml
environment:
  GOOGLE_CLIENT_ID: "your-client-id.apps.googleusercontent.com"
  GOOGLE_CLIENT_SECRET: "your-client-secret"
  GOOGLE_REDIRECT_URI: "https://imel.id/oauth-callback"
```

### 4. Restart Services
```bash
docker compose restart webmail
```

### 5. Test Login
1. Buka halaman login: `http://localhost` atau `https://your-domain.com`
2. Klik button **Login dengan Google**
3. Pilih akun Google (misal: koronx@gmail.com)
4. Sistem akan auto create user: koronx@imel.id
5. Secondary email akan di-set ke koronx@gmail.com

## Cara Kerja:

1. User klik "Login dengan Google"
2. Redirect ke Google OAuth consent screen
3. User pilih akun Google dan authorize
4. Google redirect kembali dengan authorization code
5. Server exchange code dengan access token
6. Server ambil user info dari Google (email, nama)
7. Extract username dari email Google (koronx@gmail.com → koronx)
8. Buat user baru dengan email: username@imel.id (koronx@imel.id)
9. Set secondary_email = email Google (koronx@gmail.com)
10. Auto login user

## Catatan:
- User yang dibuat otomatis memiliki random password (tidak bisa login dengan password)
- Secondary email otomatis terverifikasi
- User bisa login menggunakan Google OAuth setiap saat
- Jika user sudah ada, hanya login tanpa create akun baru

## Troubleshooting:

### Error: "redirect_uri_mismatch"
- Pastikan redirect URI di Google Console sama persis dengan yang di konfigurasi
- Jangan lupa tambahkan http:// atau https://
- Jangan ada trailing slash

### Error: "Access blocked: This app's request is invalid"
- Pastikan sudah mengaktifkan Google+ API atau People API
- Pastikan scope: `openid email profile` sudah benar

### User tidak bisa login
- Cek log container: `docker logs webmail`
- Pastikan database terhubung
- Pastikan environment variables sudah di-set dengan benar
