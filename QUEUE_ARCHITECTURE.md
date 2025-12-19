# Mail Server Queue Architecture

## Arsitektur

Sistem email server menggunakan Redis queue untuk memproses email secara asynchronous dengan multiple workers dan SMTP relay untuk external domains.

```
Email Masuk → SMTP Server → Redis Queue → Multiple Workers → {
    Local Domain (imel.id) → PostgreSQL Database
    External Domain → SMTP Relay → Destination Server
}
```

## Komponen

### 1. **SMTP Server** (mailserver)
- Menerima email dari client
- Parse email (headers, body, attachments)
- Push ke Redis queue
- Response cepat ke client tanpa menunggu penyimpanan

### 2. **Redis Queue** (mailredis)
- Antrian email menggunakan Redis list (RPUSH/BLPOP)
- Queue name: `email_queue`
- Persistent storage dengan AOF (Append Only File)

### 3. **Mail Workers** (scalable replicas)
- Multiple workers untuk memproses email secara paralel
- Blocking pop dari Redis queue (BLPOP dengan timeout 30s)
- **Routing Logic**:
  - **Local domain** (imel.id): Simpan ke PostgreSQL + file storage
  - **External domain**: Relay via SMTP ke MX server tujuan
- Auto-restart jika crash

### 4. **Database** (maildb)
- PostgreSQL untuk menyimpan email lokal dan metadata
- Tables: users, emails, attachments

## Email Routing

### Local Domain (imel.id)
1. Worker cek domain recipient
2. Jika `@imel.id`, cari user di database
3. Simpan email ke tabel `emails`
4. Simpan attachments ke `/storage/attachments`

### External Domain (koronx.com, gmail.com, dll)
1. Worker cek domain recipient
2. Jika bukan `@imel.id`, lookup MX records domain tujuan
3. Connect ke MX server dengan prioritas tertinggi
4. Kirim email via SMTP protocol:
   - EHLO imel.id
   - MAIL FROM
   - RCPT TO
   - DATA
   - Build MIME email (multipart jika ada attachment)
   - QUIT
5. Retry dengan MX server berikutnya jika gagal

## Keuntungan

✅ **Scalability**: Bisa menambah/mengurangi jumlah workers sesuai load
✅ **Reliability**: Email tidak hilang jika worker crash (masih di queue)
✅ **Performance**: SMTP server response cepat, tidak blocking
✅ **Email Relay**: Bisa kirim ke external domains (Gmail, Outlook, dll)
✅ **Monitoring**: Bisa monitor queue size di Redis
✅ **Load Distribution**: Workers otomatis ambil job dari queue
✅ **Fault Tolerance**: Retry dengan MX server lain jika gagal

## Monitoring

### Cek Queue Size
```bash
docker exec mailredis redis-cli LLEN email_queue
```

### Cek Worker Logs
```bash
docker logs imelid-mail-worker-1 -f
docker logs imelid-mail-worker-2 -f
docker logs imelid-mail-worker-3 -f
```

### Cek Stats
Workers menampilkan stats di log:
- `processed`: Jumlah email berhasil diproses
- `errors`: Jumlah email gagal

## Scaling Workers

Edit `docker-compose.yml` bagian `mail-worker`:

```yaml
mail-worker:
  deploy:
    replicas: 5  # Ubah dari 3 ke 5 untuk menambah workers
```

Lalu restart:
```bash
docker-compose up -d --scale mail-worker=5
```

## Environment Variables

### Mailserver & Workers
- `DB_HOST`: Database host
- `DB_PORT`: Database port  
- `DB_NAME`: Database name
- `DB_USER`: Database user
- `DB_PASSWORD`: Database password
- `REDIS_HOST`: Redis host
- `REDIS_PORT`: Redis port
- `DEBUG`: Enable debug logging (true/false)

## Troubleshooting

### Queue bertambah terus (workers tidak memproses)
```bash
# Cek worker logs untuk error
docker logs imelid-mail-worker-1

# Restart workers
docker-compose restart mail-worker
```

### Worker crash terus
```bash
# Cek database connection
docker exec maildb pg_isready -U mailuser

# Cek Redis connection  
docker exec mailredis redis-cli PING
```

### Clear queue (hapus semua email di antrian)
```bash
docker exec mailredis redis-cli DEL email_queue
```
