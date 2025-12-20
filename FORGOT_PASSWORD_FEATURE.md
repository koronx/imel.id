# Fitur Secondary Email & Forgot Password

## ✅ Fitur yang Ditambahkan:

### 1. **Secondary Email (Email Recovery)**
- User dapat mengatur email recovery di halaman Settings
- Email recovery digunakan untuk:
  - Reset password
  - Verifikasi 2FA (future feature)
- Email recovery tidak boleh sama dengan email utama
- Validasi format email otomatis

**Lokasi:** Settings > Email Recovery

---

### 2. **Forgot Password**
- User dapat reset password via email
- Link reset dikirim ke:
  - Email recovery (jika ada)
  - Email utama (jika tidak ada email recovery)
- Token reset berlaku 1 jam
- Token hanya bisa digunakan sekali

**Akses:** Login page > "Lupa password?"

---

### 3. **Rate Limiting**
- **Maksimal 10 percobaan per jam per email**
- Mencegah spam forgot password
- Log semua attempt dengan IP address
- Pesan error jelas jika limit tercapai

---

## 🗄️ Database Changes:

### New Columns:
```sql
users:
  - secondary_email VARCHAR(255)
```

### New Tables:
```sql
forgot_password_attempts:
  - id (SERIAL PRIMARY KEY)
  - email VARCHAR(255)
  - attempted_at TIMESTAMP
  - ip_address VARCHAR(45)
  
password_reset_tokens:
  - id (SERIAL PRIMARY KEY)
  - user_id INTEGER (FK to users)
  - token VARCHAR(64) UNIQUE
  - expires_at TIMESTAMP
  - used BOOLEAN
  - created_at TIMESTAMP
```

---

## 📧 Email Flow:

### Forgot Password Process:
1. User klik "Lupa password?" di login page
2. User masukkan email
3. System check rate limit (10x per hour)
4. Jika OK:
   - Generate token unik (64 char hex)
   - Simpan token dengan expiry 1 jam
   - Kirim email ke secondary_email atau email utama
5. User klik link di email
6. User masukkan password baru
7. Token dimark sebagai "used"
8. Redirect ke login

### Email Content:
- Plain text + HTML version
- Link reset dengan token
- Warning: link berlaku 1 jam
- Info: abaikan jika tidak request

---

## 🔒 Security Features:

✅ **Rate Limiting:**
- Max 10 attempts per hour per email
- Prevents brute force
- Logged with IP address

✅ **Token Security:**
- 64 character random hex (256-bit)
- One-time use only
- 1 hour expiration
- Can't be reused after password change

✅ **Privacy:**
- Success message tidak reveal apakah email exist
- Always show: "Jika email terdaftar, link telah dikirim"

✅ **Email Validation:**
- Secondary email must be valid format
- Secondary email cannot be same as primary
- Optional field (can be empty)

---

## 🧪 Testing:

### Test Forgot Password:
```
1. Go to login page
2. Click "Lupa password?"
3. Enter email
4. Check email (secondary or primary)
5. Click reset link
6. Enter new password
7. Login with new password
```

### Test Rate Limiting:
```
1. Try forgot password 10 times in a row
2. 11th attempt should show error:
   "Terlalu banyak percobaan. Silakan coba lagi dalam 1 jam."
```

### Test Secondary Email:
```
1. Login to account
2. Go to Settings
3. Set secondary email
4. Save
5. Test forgot password - email goes to secondary email
```

---

## 📁 Files Modified:

**Database:**
- `/db/add_secondary_email.sql` - Schema changes

**Backend:**
- `/webmail/src/index.php` - Routing for forgot-password
- `/webmail/src/pages/forgot-password.php` - NEW: Forgot password flow
- `/webmail/src/pages/settings.php` - Add secondary email form
- `/webmail/src/pages/login.php` - Add forgot password link

---

## 🎯 Next Steps (Future Enhancements):

- [ ] 2FA with TOTP (Google Authenticator)
- [ ] Email verification for secondary email
- [ ] Admin panel to view forgot password attempts
- [ ] SMS recovery option
- [ ] Security questions
