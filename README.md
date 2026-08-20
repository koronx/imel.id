# imel.id

Platform email lengkap bergaya Gmail: **mail server PHP (SMTP + IMAP)**, **REST API PHP**,
dan **webmail React + MUI**, semuanya berjalan di Docker.

![stack](https://img.shields.io/badge/PHP-8.2-777bb4) ![stack](https://img.shields.io/badge/React-18-61dafb) ![stack](https://img.shields.io/badge/MUI-5-007fff) ![stack](https://img.shields.io/badge/PostgreSQL-16-336791)

---

## Arsitektur

```
                    ┌──────────────────────────┐
   browser  ───────▶│  webmail (nginx + React) │  :8080
                    │  React 18 + MUI 5        │
                    └────────────┬─────────────┘
                                 │ /api  (proxy)
                    ┌────────────▼─────────────┐
                    │  api (PHP 8.2 + Apache)  │  :8000
                    │  REST + JWT, tanpa       │
                    │  dependency eksternal    │
                    └────────────┬─────────────┘
                                 │ PDO
   klien email ─────┐ ┌──────────▼─────────────┐
   (Thunderbird,    │ │  database (PostgreSQL) │  :5432
    Outlook, HP)    │ └──────────▲─────────────┘
                    │            │ PDO
                    │ ┌──────────┴─────────────┐
                    └▶│  mailserver (Workerman)│  :25 :587 :143
                      │  SMTP · IMAP · relay   │
                      └────────────────────────┘
                    berbagi volume ./storage (lampiran + pesan mentah)
```

| Service | Peran | Port |
|---|---|---|
| `webmail` | Klien React + MUI di balik nginx, proxy `/api` ke service api | 8080 |
| `api` | REST API PHP: auth, thread, pesan, label, lampiran, setelan | 8000 |
| `mailserver` | SMTP inbound (25), submission ber-AUTH (587), IMAP (143), worker relay | 25/587/143 |
| `database` | PostgreSQL 16 dengan full-text search | 5432 |
| `adminer` | UI database opsional (`--profile tools`) | 8081 |

---

## Menjalankan

```bash
cp .env.example .env          # ganti JWT_SECRET sebelum produksi
docker compose up -d --build
```

Buka **http://localhost:8080** lalu masuk dengan akun demo:

| Email | Password |
|---|---|
| `admin@imel.id` | `password123` |
| `fakhul@imel.id` | `password123` |
| `support@imel.id` | `password123` |

Inbox `admin@imel.id` sudah berisi contoh percakapan agar UI langsung terasa hidup.
Kirim email antar akun tersebut untuk mencoba alur pengiriman end-to-end.

Perintah lain:

```bash
docker compose logs -f mailserver     # log SMTP/IMAP/relay
docker compose --profile tools up -d  # tambah Adminer di :8081
docker compose down                   # hentikan
docker compose down -v                # hentikan + hapus data
```

---

## Fitur webmail

**Tampilan** — header pencarian ala Google, sidebar label dengan penghitung, daftar
percakapan dengan hover action, tab kategori (Utama/Sosial/Promosi/Notifikasi),
tema terang & gelap, tiga tingkat kepadatan baris, responsif sampai layar ponsel.

**Membaca** — pengelompokan percakapan (thread), badan pesan HTML dirender di
iframe *sandboxed* (skrip pengirim tidak pernah dieksekusi), lampiran dengan
pratinjau ukuran & unduhan, "Tampilkan sumber asli".

**Menulis** — jendela compose mengambang (minimize/maximize), editor rich text,
autocomplete penerima dari kontak & direktori server, Cc/Bcc, lampiran hingga 25 MB,
draf tersimpan otomatis, dan **Urungkan pengiriman** dengan jeda yang bisa diatur.

**Mengelola** — bintang, tanda penting, arsip, spam, sampah, hapus permanen, label
buatan sendiri berwarna, aksi massal, dan pembatalan (undo) untuk aksi destruktif.

**Pencarian** — operator ala Gmail plus dialog filter lanjutan:

```
from:budi  to:tim  subject:laporan  label:work  in:trash
is:unread  is:starred  is:important  has:attachment  filename:pdf
before:2026-01-01  after:2025-12-01  newer_than:7d  older_than:2m
"frasa persis"  -kata_yang_dikecualikan
```

**Pintasan keyboard** — `c` tulis, `/` cari, `g` lalu `i/s/t/d` pindah folder,
`e` arsip, `#` hapus, `u` kembali, `r` muat ulang, `?` daftar pintasan.

---

## REST API

Semua endpoint diawali `/api`. Autentikasi memakai `Authorization: Bearer <token>`.

| Method | Endpoint | Keterangan |
|---|---|---|
| POST | `/auth/register` · `/auth/login` | daftar & masuk (mengembalikan JWT) |
| GET | `/auth/me` | profil + setelan |
| PUT | `/auth/profile` · `/auth/password` | ubah profil / password |
| GET/DELETE | `/auth/sessions[/{jti}]` | daftar & cabut sesi perangkat |
| GET/POST/PUT/DELETE | `/labels[/{id}]` | label beserta penghitung |
| GET | `/threads?label=&q=&page=&per_page=` | daftar percakapan |
| GET | `/threads/{thread_key}` | isi satu percakapan |
| GET | `/messages/{id}` · `/messages/{id}/raw` | detail pesan & sumber RFC822 |
| GET | `/messages/{id}/reply-context?mode=reply\|reply_all\|forward` | isi awal balasan |
| POST | `/messages/batch` | aksi massal: read, star, archive, trash, spam, label, … |
| POST | `/messages/send` | kirim pesan |
| POST/DELETE | `/drafts[/{id}]` | simpan / hapus draf |
| POST/GET/DELETE | `/attachments[/{id}]` | unggah, unduh, hapus lampiran |
| GET | `/contacts?q=` | autocomplete penerima |
| GET/PUT | `/settings` | preferensi pengguna |
| GET | `/health` | pemeriksaan kesehatan |

Contoh:

```bash
TOKEN=$(curl -s localhost:8000/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@imel.id","password":"password123"}' | jq -r .auth.token)

curl -s localhost:8000/api/threads?label=inbox -H "Authorization: Bearer $TOKEN" | jq
```

---

## Mail server

* **SMTP :25** — menerima email masuk untuk domain sendiri, menolak relay dari pihak luar.
* **SMTP :587** — submission dengan `AUTH LOGIN`/`AUTH PLAIN`; alamat pengirim wajib
  cocok dengan akun yang login.
* **IMAP :143** — `LOGIN`, `LIST`, `SELECT/EXAMINE`, `STATUS`, `SEARCH`, `FETCH`
  (termasuk `UID FETCH`, `BODY[]`, `ENVELOPE`), `STORE` flag, `EXPUNGE`, `LOGOUT`.
* **Worker relay** — email ke domain luar masuk antrean `outbound_queue`, dicoba
  ulang dengan backoff, dan mengirim notifikasi kegagalan ke inbox pengirim.

Uji cepat dari terminal:

```bash
# kirim email langsung lewat SMTP
printf 'EHLO test\r\nMAIL FROM:<admin@imel.id>\r\nRCPT TO:<fakhul@imel.id>\r\nDATA\r\nSubject: Halo\r\n\r\nPesan uji.\r\n.\r\nQUIT\r\n' \
  | nc localhost 25

# baca lewat IMAP
printf 'a1 LOGIN "admin@imel.id" "password123"\r\na2 SELECT INBOX\r\na3 FETCH 1 (FLAGS ENVELOPE)\r\na4 LOGOUT\r\n' \
  | nc localhost 143
```

### Mengirim ke domain luar

Relay dimatikan secara bawaan. Untuk mengaktifkannya, isi `.env`:

```env
RELAY_ENABLED=true
SMTP_RELAY_HOST=smtp.provider.com
SMTP_RELAY_PORT=587
SMTP_RELAY_USER=akun
SMTP_RELAY_PASSWORD=rahasia
```

Tanpa `SMTP_RELAY_HOST`, worker mencoba resolusi MX dan mengirim langsung — pada
banyak jaringan port 25 keluar diblokir, jadi smart host lebih disarankan.

---

## Struktur project

```
imel.id/
├── docker-compose.yml
├── .env.example
├── db/init.sql               # skema + data contoh
├── shared/php/               # library dipakai api & mailserver
│   ├── autoload.php
│   └── src/
│       ├── Db.php  Util.php  Storage.php  MailStore.php
│       ├── Mime/{Parser,Builder}.php
│       └── Smtp/Client.php
├── api/                      # REST API (PHP murni, tanpa composer)
│   ├── Dockerfile
│   ├── public/index.php      # front controller + tabel rute
│   └── src/
│       ├── Auth.php  Jwt.php  Mailer.php  MessageQuery.php  Serializer.php
│       ├── Http/{Request,Response,Router}.php
│       └── Controllers/*.php
├── mailserver/               # Workerman SMTP/IMAP + relay
│   ├── Dockerfile
│   └── src/{server.php,lib/*.php}
├── client/                   # React 18 + MUI 5 (Vite → nginx)
│   ├── Dockerfile  nginx.conf
│   └── src/{api,state,components,pages,utils}
├── storage/                  # lampiran & pesan mentah (volume bersama)
└── legacy/webmail-php/       # webmail PHP lama, diarsipkan sebagai referensi
```

> Webmail PHP versi lama dipindahkan ke `legacy/webmail-php/` dan tidak lagi dirujuk
> `docker-compose.yml`. Direktori kosong `webmail/` boleh dihapus setelah container
> lama dihentikan (`docker compose down`) karena masih terkunci oleh bind mount lama.

---

## Pengembangan

Frontend dengan hot reload sambil backend tetap di Docker:

```bash
docker compose up -d database api mailserver
cd client && npm install && npm run dev      # http://localhost:5173
```

Vite mem-proxy `/api` ke `http://localhost:8000`; ubah dengan `VITE_API_TARGET`.

Perubahan kode PHP butuh `docker compose build api` (atau `mailserver`) karena
kode disalin ke dalam image.

---

## Catatan keamanan

* Password di-hash dengan bcrypt; token JWT HS256 punya catatan sesi yang bisa dicabut.
* Ganti password akun demo saat digunakan di lingkungan nyata.
* HTML pesan dibersihkan di server (script/iframe/handler event dibuang) **dan**
  dirender di iframe sandbox pada klien.
* Path lampiran divalidasi terhadap direktori `storage` untuk mencegah path traversal.
* Kirim ke luar hanya bisa oleh pengguna terautentikasi; SMTP :25 menolak relay.
* Untuk produksi: pasang TLS di depan (reverse proxy), set `JWT_SECRET` acak panjang,
  batasi `CORS_ORIGINS`, dan tambahkan SPF/DKIM/DMARC pada DNS domain Anda.

## Lisensi

MIT
