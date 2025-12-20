#!/bin/bash

echo "==================================="
echo "Test Webmail Inbox - koronx@imel.id"
echo "==================================="
echo ""

# Login ke webmail
echo "1. Login ke webmail..."
COOKIE_FILE="/tmp/webmail_cookie.txt"
rm -f $COOKIE_FILE

LOGIN_RESPONSE=$(curl -s -c $COOKIE_FILE -X POST 'http://localhost/?page=login' \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "email=koronx@imel.id&password=password123")

# Check if login successful
if echo "$LOGIN_RESPONSE" | grep -q "Inbox\|Dashboard\|Email"; then
    echo "✅ Login berhasil"
else
    echo "❌ Login gagal"
    echo "$LOGIN_RESPONSE" | head -20
    exit 1
fi

echo ""
echo "2. Akses halaman inbox..."
INBOX_PAGE=$(curl -s -b $COOKIE_FILE 'http://localhost/?page=inbox')

# Count emails in inbox
EMAIL_COUNT=$(echo "$INBOX_PAGE" | grep -o 'data-email-id="[0-9]*"' | wc -l | tr -d ' ')

echo "📧 Ditemukan $EMAIL_COUNT email di inbox"

if [ "$EMAIL_COUNT" -gt 0 ]; then
    echo "✅ Email berhasil ditampilkan di webmail!"
    echo ""
    echo "Email dari test@imel.id:"
    echo "$INBOX_PAGE" | grep -o 'test@imel.id' | head -3 | while read email; do
        echo "  - $email"
    done
else
    echo "❌ Tidak ada email di inbox"
    echo ""
    echo "Debug: Cek query di inbox.php"
fi

echo ""
echo "==================================="
echo "Test selesai!"
echo "==================================="
echo ""
echo "Buka browser: http://localhost/"
echo "Login: koronx@imel.id / password123"

rm -f $COOKIE_FILE
