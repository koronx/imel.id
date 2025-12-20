#!/bin/bash

# Test sending email via API
# This script tests the updated API that uses user_id and folder

echo "=== Testing Email Send via API ==="
echo ""

# Step 1: Login as test@imel.id
echo "Step 1: Login as test@imel.id"
LOGIN_RESPONSE=$(curl -s -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"test@imel.id","password":"password123"}')

echo "Login Response: $LOGIN_RESPONSE"
TOKEN=$(echo $LOGIN_RESPONSE | grep -o '"token":"[^"]*' | grep -o '[^"]*$')
echo "Token: $TOKEN"
echo ""

# Step 2: Send email from test@imel.id to koronx@imel.id
echo "Step 2: Send email from test@imel.id to koronx@imel.id"
SEND_RESPONSE=$(curl -s -X POST 'http://localhost/api.php?action=send_email' \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "to": "koronx@imel.id",
    "subject": "Test API with user_id",
    "body": "This email tests the new API structure using user_id and folder columns"
  }')

echo "Send Response: $SEND_RESPONSE"
EMAIL_ID=$(echo $SEND_RESPONSE | grep -o '"email_id":[0-9]*' | grep -o '[0-9]*$')
echo "Email ID: $EMAIL_ID"
echo ""

# Step 3: Check sent folder for test@imel.id
echo "Step 3: Check sent folder for test@imel.id"
SENT_RESPONSE=$(curl -s 'http://localhost/api.php?action=get_sent' \
  -H "Authorization: Bearer $TOKEN")

echo "Sent emails count: $(echo $SENT_RESPONSE | grep -o '"id"' | wc -l)"
echo "Latest sent email: $(echo $SENT_RESPONSE | grep -o '"subject":"[^"]*' | head -1)"
echo ""

# Step 4: Login as koronx@imel.id
echo "Step 4: Login as koronx@imel.id"
LOGIN_RESPONSE2=$(curl -s -X POST 'http://localhost/api.php?action=login' \
  -H "Content-Type: application/json" \
  -d '{"email":"koronx@imel.id","password":"password123"}')

TOKEN2=$(echo $LOGIN_RESPONSE2 | grep -o '"token":"[^"]*' | grep -o '[^"]*$')
echo "Token: $TOKEN2"
echo ""

# Step 5: Check inbox for koronx@imel.id
echo "Step 5: Check inbox for koronx@imel.id"
INBOX_RESPONSE=$(curl -s 'http://localhost/api.php?action=get_inbox' \
  -H "Authorization: Bearer $TOKEN2")

echo "Inbox emails count: $(echo $INBOX_RESPONSE | grep -o '"id"' | wc -l)"
echo "Latest inbox email: $(echo $INBOX_RESPONSE | grep -o '"subject":"[^"]*' | head -1)"
echo ""

# Step 6: Verify in database
echo "Step 6: Verify in database"
docker exec -i maildb psql -U mailuser maildb -c \
  "SELECT user_id, folder, from_email, to_email, subject FROM emails WHERE id = $EMAIL_ID;"

echo ""
echo "=== Test Complete ==="
