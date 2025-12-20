#!/bin/bash

# Script untuk test email flow lengkap

echo "==================================="
echo "Test Email Flow - imel.id Mobile App"
echo "==================================="

echo ""
echo "1. Login sebagai test@imel.id..."
TEST_LOGIN=$(curl -s -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"test@imel.id","password":"password123"}')

TEST_TOKEN=$(echo $TEST_LOGIN | python3 -c "import sys, json; print(json.load(sys.stdin)['token'])" 2>/dev/null || echo "")

if [ -z "$TEST_TOKEN" ]; then
  echo "❌ Login gagal untuk test@imel.id"
  exit 1
fi
echo "✅ Login berhasil: test@imel.id"

echo ""
echo "2. Login sebagai koronx@imel.id..."
KORONX_LOGIN=$(curl -s -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"koronx@imel.id","password":"password123"}')

KORONX_TOKEN=$(echo $KORONX_LOGIN | python3 -c "import sys, json; print(json.load(sys.stdin)['token'])" 2>/dev/null || echo "")

if [ -z "$KORONX_TOKEN" ]; then
  echo "❌ Login gagal untuk koronx@imel.id"
  exit 1
fi
echo "✅ Login berhasil: koronx@imel.id"

echo ""
echo "3. Cek inbox koronx@imel.id..."
INBOX=$(curl -s -X GET "http://localhost/api.php?action=get_inbox&limit=5" \
  -H "Authorization: Bearer $KORONX_TOKEN")

EMAIL_COUNT=$(echo $INBOX | python3 -c "import sys, json; print(len(json.load(sys.stdin)['emails']))" 2>/dev/null || echo "0")

echo "📧 Total email di inbox: $EMAIL_COUNT"

if [ "$EMAIL_COUNT" -gt 0 ]; then
  echo ""
  echo "Email terakhir:"
  echo $INBOX | python3 -c "
import sys, json
data = json.load(sys.stdin)
if data['emails']:
    email = data['emails'][0]
    print(f\"  Dari: {email['sender']}\")
    print(f\"  Subjek: {email['subject']}\")
    print(f\"  Tanggal: {email['created_at']}\")
    print(f\"  Dibaca: {'Ya' if email['is_read'] else 'Belum'}\")
" 2>/dev/null
fi

echo ""
echo "4. Kirim email test dari test@imel.id ke koronx@imel.id..."
SEND_RESULT=$(curl -s -X POST "http://localhost/api.php?action=send_email" \
  -H "Authorization: Bearer $TEST_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "koronx@imel.id",
    "subject": "Test Script Email",
    "body": "Ini adalah test email yang dikirim menggunakan script test"
  }')

SEND_SUCCESS=$(echo $SEND_RESULT | python3 -c "import sys, json; print(json.load(sys.stdin)['success'])" 2>/dev/null || echo "False")

if [ "$SEND_SUCCESS" = "True" ]; then
  echo "✅ Email berhasil dikirim"
else
  echo "❌ Gagal mengirim email"
  echo $SEND_RESULT
fi

echo ""
echo "5. Cek inbox koronx@imel.id lagi..."
NEW_INBOX=$(curl -s -X GET "http://localhost/api.php?action=get_inbox&limit=5" \
  -H "Authorization: Bearer $KORONX_TOKEN")

NEW_EMAIL_COUNT=$(echo $NEW_INBOX | python3 -c "import sys, json; print(len(json.load(sys.stdin)['emails']))" 2>/dev/null || echo "0")

echo "📧 Total email di inbox sekarang: $NEW_EMAIL_COUNT"

if [ "$NEW_EMAIL_COUNT" -gt "$EMAIL_COUNT" ]; then
  echo "✅ Email baru sudah masuk!"
else
  echo "⚠️  Email belum muncul (mungkin perlu waktu sebentar)"
fi

echo ""
echo "==================================="
echo "✅ Test selesai!"
echo "==================================="
echo ""
echo "Untuk melihat di aplikasi Flutter:"
echo "1. Login sebagai: koronx@imel.id"
echo "2. Password: password123"
echo "3. Buka Kotak Masuk"
echo "4. Email dari test@imel.id akan muncul"
