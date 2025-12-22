# Fitur Manajemen Queue Email

## Deskripsi
Fitur manajemen queue email di dashboard admin memungkinkan administrator untuk melihat, mencari, dan mengelola email yang sedang dalam antrian untuk dikirim.

## Fitur yang Tersedia

### 1. **Lihat Queue Email**
- Menampilkan daftar semua email yang ada di Redis queue
- Menampilkan maksimal 100 email pertama
- Informasi yang ditampilkan:
  - Nomor urut
  - Pengirim (From)
  - Penerima (To)
  - Subject
  - Ukuran email
  - Waktu diterima
  - Indikator attachment

### 2. **Statistik Queue**
- Total email di queue
- Jumlah email yang ditampilkan
- Status aktif

### 3. **Pencarian**
- Cari email berdasarkan:
  - Alamat pengirim (from)
  - Alamat penerima (to)
  - Subject email
- Pencarian case-insensitive
- Real-time filtering

### 4. **Hapus Email Individual**
- Hapus email tertentu dari queue
- Konfirmasi sebelum menghapus
- Otomatis hapus temp file jika ada

### 5. **Hapus Semua Queue**
- Hapus semua email dari queue sekaligus
- Double confirmation untuk keamanan
- Otomatis hapus semua temp files

## Akses

Fitur ini **hanya dapat diakses oleh admin** (admin@imel.id) di halaman Dashboard Admin.

URL: `http://your-domain.com/?page=dashboard`

## API Endpoints

### 1. GET Queue
```
GET /api.php?action=admin_get_queue
Authorization: Bearer <token>
```

Response:
```json
{
  "success": true,
  "queue_length": 10,
  "items": [
    {
      "index": 0,
      "from": "sender@example.com",
      "to": "user@imel.id",
      "subject": "Test Email",
      "size": 1024,
      "received_at": "2025-12-22 10:30:00",
      "has_attachments": false,
      "temp_file": null
    }
  ],
  "showing": 10
}
```

### 2. Search Queue
```
GET /api.php?action=admin_search_queue&q=search_term
Authorization: Bearer <token>
```

Response: sama seperti GET Queue dengan tambahan field `search_query` dan `found`

### 3. Delete Queue Item
```
POST /api.php?action=admin_delete_queue_item
Authorization: Bearer <token>
Content-Type: application/json

{
  "index": 0
}
```

Response:
```json
{
  "success": true,
  "message": "Email berhasil dihapus dari queue"
}
```

### 4. Clear All Queue
```
POST /api.php?action=admin_clear_queue
Authorization: Bearer <token>
Content-Type: application/json

{}
```

Response:
```json
{
  "success": true,
  "deleted_count": 10,
  "message": "Semua email berhasil dihapus dari queue"
}
```

## Cara Penggunaan

### Melihat Queue
1. Login sebagai admin@imel.id
2. Buka halaman Dashboard
3. Scroll ke section "Manajemen Queue Email"
4. Queue akan otomatis dimuat saat halaman dibuka

### Refresh Queue
Klik tombol "Refresh" di header card untuk memuat ulang data queue terbaru.

### Mencari Email
1. Ketik kata kunci di search box (from, to, atau subject)
2. Klik tombol "Cari" atau tekan Enter
3. Klik "Reset" untuk kembali ke tampilan semua queue

### Menghapus Email Individual
1. Klik tombol trash icon (merah) di kolom Aksi
2. Konfirmasi penghapusan
3. Email akan dihapus dari queue

### Menghapus Semua Queue
1. Klik tombol "Hapus Semua Queue" (merah)
2. Konfirmasi pertama
3. Konfirmasi kedua
4. Semua email akan dihapus dari queue

## Keamanan

- ✅ Hanya admin yang dapat mengakses fitur ini
- ✅ Token authentication required
- ✅ Double confirmation untuk hapus semua
- ✅ Temp files otomatis dihapus
- ✅ Validasi input di backend

## Technical Details

### Database
Tidak ada perubahan database. Fitur ini hanya berinteraksi dengan Redis queue.

### Redis Queue
- Queue name: `email_queue`
- Data structure: List (FIFO)
- Operations: LLEN, LRANGE, LSET, LREM, DEL

### Temp Files
- Location: `/storage/temp_emails/`
- Otomatis dihapus saat email dihapus dari queue
- Format: `email_<uniqid>.eml`

## UI Components

### Card Header
- Title: "Manajemen Queue Email"
- Refresh button

### Search Bar
- Input field untuk search query
- Button "Cari" dan "Reset"

### Statistics
- 3 info boxes menampilkan:
  - Total Queue
  - Ditampilkan
  - Status/Hasil Pencarian

### Table
- Responsive table dengan kolom:
  - # (nomor)
  - From
  - To
  - Subject (dengan icon attachment jika ada)
  - Size
  - Received At
  - Aksi (button hapus)

### Actions
- Delete button (per item)
- Clear all button

## Error Handling

- Connection error ke Redis: Menampilkan error message
- Item not found: Alert dengan error message
- Authorization failed: 403 Unauthorized
- Invalid token: 401 Unauthorized

## Files Modified

1. `/root/imel.id/webmail/api.php`
   - Added 4 new endpoints untuk queue management

2. `/root/imel.id/webmail/src/pages/dashboard.php`
   - Added UI section untuk queue management
   - Added JavaScript functions untuk queue operations

## Testing

### Manual Testing Steps:
1. Send beberapa email untuk mengisi queue
2. Login sebagai admin
3. Verify queue ditampilkan dengan benar
4. Test search functionality
5. Test delete individual item
6. Test clear all queue
7. Verify temp files terhapus

### Expected Behavior:
- Queue loading: < 1 second
- Search: Instant filtering
- Delete item: Immediate update
- Clear all: Batch deletion with confirmation

## Future Enhancements

- [ ] Pagination untuk queue besar (> 100 items)
- [ ] Filter by date/time
- [ ] View email content dari queue
- [ ] Retry failed emails
- [ ] Priority queue management
- [ ] Queue statistics & analytics
- [ ] Export queue data
- [ ] Pause/Resume queue processing
