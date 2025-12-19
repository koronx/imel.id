# imel.id

Aplikasi email custom dengan mailserver berbasis PHP Workerman dan webmail interface

## 🚀 Fitur

### Mailserver
- **SMTP Server** (Port 25, 587) - Menerima dan mengirim email
- **IMAP Server** (Port 143, 993) - Mengambil email dari server
- **PHP Workerman** - Asynchronous event-driven framework
- **PostgreSQL** - Database untuk menyimpan email dan user

### Webmail
- **Registrasi User** - Buat akun dengan domain @imel.id
- **Login/Logout** - Autentikasi berbasis session
- **Inbox** - Lihat daftar email masuk
- **Compose** - Tulis dan kirim email baru
- **Attachments** - Support lampiran file (max 50MB)
- **Folder Management** - Inbox, Sent, Drafts, Trash
- **Email Reading** - Baca email dengan detail lengkap
- **Rate Limiting** - Batasi pengiriman email eksternal (10 email/jam per user)

## 📋 Requirement

- Docker
- Docker Compose

## 🔧 Instalasi

1. Clone repository
```bash
git clone <repository-url>
cd imel.id
```

2. Jalankan aplikasi dengan Docker Compose
```bash
docker-compose up -d
```

3. Tunggu beberapa saat hingga semua service siap

## 🌐 Akses Aplikasi

- **Webmail**: http://localhost:8080
- **PostgreSQL**: localhost:5432
- **SMTP**: localhost:25
- **IMAP**: localhost:143

## 👤 Default User

Email: `admin@imel.id`  
Password: `password123`

## 📝 Cara Penggunaan

### Registrasi User Baru
1. Buka http://localhost:8080
2. Klik "Daftar di sini"
3. Isi form dengan email berakhiran @imel.id
4. Login dengan akun yang baru dibuat

### Mengirim Email
1. Login ke webmail
2. Klik "Tulis Email"
3. Isi form (To, Subject, Body)
4. Tambahkan attachment jika perlu
5. Klik "Kirim"

### Membaca Email
1. Email masuk akan muncul di Inbox
2. Klik email untuk membaca detail
3. Download attachment jika ada

### Testing SMTP Manual (Optional)
```bash
telnet localhost 25
HELO imel.id
MAIL FROM:<sender@imel.id>
RCPT TO:<receiver@imel.id>
DATA
Subject: Test Email

This is a test email.
.
QUIT
```

## 🗂️ Struktur Project

```
imel.id/
├── docker-compose.yml       # Konfigurasi Docker
├── db/
│   └── init.sql            # Schema database
├── mailserver/
│   ├── Dockerfile          # Docker image mailserver
│   ├── composer.json       # Dependencies PHP
│   └── src/
│       └── server.php      # SMTP & IMAP server
└── webmail/
    ├── Dockerfile          # Docker image webmail
    └── src/
        ├── index.php       # Main application
        └── pages/          # Page components
            ├── login.php
            ├── register.php
            ├── inbox.php
            ├── compose.php
            ├── view.php
            └── send.php
```

## 🔌 Database Schema

### Users Table
- id (Primary Key)
- email (Unique)
- password (Hashed)
- full_name
- created_at
- last_login

### Emails Table
- id (Primary Key)
- message_id (Unique)
- user_id (Foreign Key)
- from_email
- to_email
- cc, bcc
- subject
- body, html_body
- is_read, is_starred
- folder (inbox, sent, drafts, trash)
- received_at, sent_at
- size

### Attachments Table
- id (Primary Key)
- email_id (Foreign Key)
- filename
- content_type
- size
- storage_path
- created_at

## 🛠️ Development

### Rebuild Containers
```bash
docker-compose down
docker-compose up --build
```

### View Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f mailserver
docker-compose logs -f webmail
docker-compose logs -f database
```

### Access Database
```bash
docker exec -it maildb psql -U mailuser -d maildb
```

### Stop Services
```bash
docker-compose down
```

### Remove All Data
```bash
docker-compose down -v
```

## 📚 Technology Stack

- **Backend**: PHP 8.2
- **Framework**: Workerman 4.x
- **Database**: PostgreSQL 15
- **Web Server**: Apache
- **Container**: Docker & Docker Compose

## 🔒 Security Notes

- Password di-hash menggunakan bcrypt
- Session-based authentication
- Input validation untuk mencegah SQL injection
- File upload size limit (50MB)
- **Rate limiting**: Email eksternal dibatasi 10 email/jam per user untuk mencegah spam

## 🚦 Rate Limiting

Sistem memiliki fitur rate limiting untuk pengiriman email ke domain eksternal (non @imel.id):

- **Limit**: 10 email per jam per user
- **Scope**: Hanya berlaku untuk email eksternal
- **Window**: Rolling 1 jam (bukan per jam kalender)
- **Enforcement**: Diterapkan di webmail saat compose dan di mail worker saat processing

Jika user mencoba mengirim lebih dari 10 email eksternal dalam 1 jam:
- Di **webmail**: Email ditolak dengan pesan error
- Di **worker**: Email tidak dikirim dan dicatat di log

Untuk detail lengkap, lihat [RATE_LIMIT.md](RATE_LIMIT.md)

### Testing Rate Limit
```bash
# Check rate limit status
./test_rate_limit.sh check admin@imel.id

# View recent external emails
./test_rate_limit.sh recent

# Simulate sending email (for testing)
./test_rate_limit.sh simulate admin@imel.id test@gmail.com

# Clear rate limit (for testing)
./test_rate_limit.sh clear admin@imel.id

# Run automatic test
./test_rate_limit.sh test
```

## 📄 License

MIT License

## 👥 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📧 Support

Untuk pertanyaan dan support, silakan buat issue di repository ini.
