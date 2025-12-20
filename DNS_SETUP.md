# DNS Records untuk imel.id

## Setup DKIM sudah selesai di mailserver!

Sekarang Anda perlu menambahkan DNS records berikut:

---

### 1. DKIM Record (TXT)
```
Host/Name: default._domainkey.imel.id
Type: TXT
Value: v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr7dcN4uoWz5QzdL3ZnEjMwR7Uf3o/P9Z9PquZ16OClVnxR4F0POnRhBinfLTlRkGNRJSYWALO0G7kF0aP7HE+SDKubLssi7r/2jvRgY4gtgV+4gks/gmHs5xKwP0WLI56qewh9WRqhQOH7XCjag57Ohxhz4VkWweXvrqEpWG6d/K1fxLc+UICfW/LW+us8iBsHuRI1XH3h6abgYET30vRU9gQOu4LmI+l2ALLFkGD9v2SW7bFDBmUPHXkeK3BVtn4P4ItKTm+eESDWhhCHYcUbKdFMKhNHrEYx0M1Zp2Rzyty35LOwjuPhTrT89dmpAXWpQ71MaPJqihCMTexKliVwIDAQAB
TTL: 3600
```

---

### 2. DMARC Record (TXT)
```
Host/Name: _dmarc.imel.id
Type: TXT
Value: v=DMARC1; p=quarantine; rua=mailto:dmarc@imel.id; pct=100; adkim=r; aspf=r
TTL: 3600
```

**Penjelasan:**
- `p=quarantine`: Email yang gagal DMARC akan masuk spam/quarantine
- `rua=mailto:dmarc@imel.id`: Report akan dikirim ke email ini
- `pct=100`: Apply policy ke 100% email
- `adkim=r`: DKIM alignment relaxed
- `aspf=r`: SPF alignment relaxed

---

### 3. SPF Record (TXT) - Pastikan sudah ada
```
Host/Name: imel.id (atau @)
Type: TXT
Value: v=spf1 ip4:103.167.34.27 ~all
TTL: 3600
```

---

### 4. PTR Record (Reverse DNS)
**Minta provider VPS Anda untuk set:**
```
103.167.34.27 → imel.id
atau
103.167.34.27 → mail.imel.id
```

---

## Cara Test Setelah Setup DNS:

### 1. Test DKIM & SPF:
Kirim email ke: **check-auth@verifier.port25.com**

Mereka akan auto-reply dengan hasil test lengkap.

### 2. Test Manual:
```bash
# Check DKIM record
dig TXT default._domainkey.imel.id +short

# Check DMARC record  
dig TXT _dmarc.imel.id +short

# Check SPF record
dig TXT imel.id +short

# Check PTR record
dig -x 103.167.34.27 +short
```

---

## Timeline:
- DNS propagation: 15 menit - 48 jam (biasanya < 1 jam)
- Setelah DNS propagate, kirim test email ke Gmail
- Check Gmail source (Show Original) untuk lihat hasil DKIM & DMARC

---

## Yang Sudah Dikerjakan:
✅ Generate DKIM keys (private & public)
✅ Implementasi DKIM signing di mailserver
✅ Update Dockerfile untuk include DKIM keys
✅ Rebuild & restart mailserver

## Yang Harus Anda Lakukan:
🔲 Tambahkan 4 DNS records di atas
🔲 Request PTR record ke provider VPS
🔲 Tunggu DNS propagate
🔲 Test kirim email ke Gmail
