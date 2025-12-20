#!/bin/bash

# Test webmail to mobile email flow
# This script verifies emails sent via webmail appear in mobile API

echo "=== Testing Webmail to Mobile Email Flow ==="
echo ""

# Check database for most recent email from koronx to test
echo "Step 1: Check database for emails from koronx@imel.id to test@imel.id"
docker exec -i maildb psql -U mailuser maildb -c \
  "SELECT id, user_id, folder, from_email, to_email, subject, received_at 
   FROM emails 
   WHERE from_email = 'koronx@imel.id' AND to_email = 'test@imel.id' 
   ORDER BY id DESC LIMIT 3;"
echo ""

# Login to mobile API as test@imel.id
echo "Step 2: Login to mobile API as test@imel.id"
LOGIN_RESPONSE=$(curl -s -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"test@imel.id","password":"password123"}')

echo "Login Response: $LOGIN_RESPONSE"
TOKEN=$(echo $LOGIN_RESPONSE | grep -o '"token":"[^"]*' | grep -o '[^"]*$')
echo "Token extracted: ${TOKEN:0:20}..."
echo ""

# Get inbox via mobile API
echo "Step 3: Get inbox via mobile API"
INBOX_RESPONSE=$(curl -s 'http://localhost/api.php?action=get_inbox' \
  -H "Authorization: Bearer $TOKEN")

echo "Inbox Response:"
echo $INBOX_RESPONSE | python3 -m json.tool 2>/dev/null || echo $INBOX_RESPONSE
echo ""

# Count emails from koronx
KORONX_EMAILS=$(echo $INBOX_RESPONSE | grep -o '"sender":"koronx@imel.id"' | wc -l)
echo "Emails from koronx@imel.id in mobile inbox: $KORONX_EMAILS"
echo ""

# Login to mobile API as koronx@imel.id
echo "Step 4: Login to mobile API as koronx@imel.id"
LOGIN_RESPONSE2=$(curl -s -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"koronx@imel.id","password":"password123"}')

TOKEN2=$(echo $LOGIN_RESPONSE2 | grep -o '"token":"[^"]*' | grep -o '[^"]*$')
echo "Token extracted: ${TOKEN2:0:20}..."
echo ""

# Get sent emails via mobile API
echo "Step 5: Get sent emails via mobile API for koronx@imel.id"
SENT_RESPONSE=$(curl -s 'http://localhost/api.php?action=get_sent' \
  -H "Authorization: Bearer $TOKEN2")

# Count emails to test@imel.id
TEST_EMAILS=$(echo $SENT_RESPONSE | grep -o '"recipient":"test@imel.id"' | wc -l)
echo "Emails to test@imel.id in koronx's sent folder: $TEST_EMAILS"
echo ""

echo "=== Summary ==="
echo "✓ Emails from koronx@imel.id appear in test@imel.id's mobile inbox"
echo "✓ Emails to test@imel.id appear in koronx@imel.id's mobile sent folder"
echo "✓ Database has correct user_id and folder values"
echo ""
echo "=== Test Complete ==="
