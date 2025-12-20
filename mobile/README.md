# Aplikasi Mobile imel.id

Aplikasi mobile email client untuk layanan imel.id berbasis Flutter.

## Fitur

- Login & Register
- Kotak Masuk (Inbox)
- Email Terkirim (Sent)
- Kirim Email Baru
- Detail Email
- Hapus Email
- Tandai Sudah Dibaca

## Setup

### 1. Install Dependencies

```bash
cd mobile
flutter pub get
```

### 2. Konfigurasi API

Edit file `lib/services/api_service.dart` dan ubah `baseUrl` sesuai dengan server Anda:

```dart
static const String baseUrl = 'http://your-server.com/api.php';
```

Untuk development lokal:
- Android Emulator: `http://10.0.2.2:8080/api.php`
- iOS Simulator: `http://localhost:8080/api.php`
- Physical Device: `http://YOUR_COMPUTER_IP:8080/api.php`

### 3. Jalankan Aplikasi

```bash
# Run di Android
flutter run -d android

# Run di iOS (Mac only)
flutter run -d ios

# Run di Chrome (untuk testing)
flutter run -d chrome
```

## Struktur Folder

```
mobile/lib/
├── main.dart                 # Entry point aplikasi
├── models/                   # Data models
│   ├── user.dart
│   ├── email_message.dart
│   └── attachment.dart
├── providers/                # State management
│   └── auth_provider.dart
├── screens/                  # UI Screens
│   ├── login_screen.dart
│   ├── register_screen.dart
│   ├── inbox_screen.dart
│   ├── email_detail_screen.dart
│   └── compose_screen.dart
└── services/                 # API Services
    └── api_service.dart
```

## API Backend

API backend tersedia di `webmail/api.php` dengan endpoints berikut:

### Public Endpoints (No Auth Required)
- `POST /api.php?action=login` - Login user
- `POST /api.php?action=register` - Register user baru

### Protected Endpoints (Auth Required)
- `GET /api.php?action=get_user` - Get current user info
- `GET /api.php?action=get_inbox` - Get inbox emails
- `GET /api.php?action=get_sent` - Get sent emails
- `GET /api.php?action=get_email&id={id}` - Get email detail
- `POST /api.php?action=send_email` - Send new email
- `POST /api.php?action=mark_read` - Mark email as read
- `POST /api.php?action=delete_email` - Delete email

## Authentication

Aplikasi menggunakan Bearer Token authentication. Token disimpan secara aman menggunakan `flutter_secure_storage`.

Header format:
```
Authorization: Bearer {token}
```

## Build untuk Production

### Android
```bash
flutter build apk --release
# Output: build/app/outputs/flutter-apk/app-release.apk
```

### iOS
```bash
flutter build ios --release
# Kemudian buka dengan Xcode untuk signing dan distribute
```

## Troubleshooting

### Network Error
Pastikan:
1. Server backend sudah running
2. CORS sudah dikonfigurasi dengan benar di `api.php`
3. Base URL di `api_service.dart` sudah benar
4. Firewall tidak memblokir koneksi

### iOS Simulator
Untuk iOS Simulator, tambahkan permission di `ios/Runner/Info.plist`:
```xml
<key>NSAppTransportSecurity</key>
<dict>
    <key>NSAllowsArbitraryLoads</key>
    <true/>
</dict>
```

### Android Emulator
Android Emulator menggunakan IP khusus `10.0.2.2` untuk mengakses localhost komputer host.
