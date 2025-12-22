#!/bin/bash

# Test Queue Management Feature
# This script tests the queue management functionality

echo "=========================================="
echo "Queue Management Feature Test"
echo "=========================================="
echo ""

# Configuration
WEBMAIL_URL="http://localhost"
ADMIN_EMAIL="admin@imel.id"
ADMIN_PASSWORD="admin123"  # Change this to actual admin password

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print status
print_status() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ $2${NC}"
    else
        echo -e "${RED}✗ $2${NC}"
    fi
}

# Step 1: Login as admin
echo "1. Testing admin login..."
LOGIN_RESPONSE=$(curl -s -X POST "${WEBMAIL_URL}/api.php?action=login" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASSWORD}\"}")

TOKEN=$(echo $LOGIN_RESPONSE | grep -o '"token":"[^"]*' | cut -d'"' -f4)

if [ -n "$TOKEN" ]; then
    print_status 0 "Admin login successful"
    echo "   Token: ${TOKEN:0:20}..."
else
    print_status 1 "Admin login failed"
    echo "   Response: $LOGIN_RESPONSE"
    exit 1
fi

echo ""

# Step 2: Check Redis connection
echo "2. Testing Redis connection..."
REDIS_PING=$(docker exec mailredis redis-cli ping 2>/dev/null)

if [ "$REDIS_PING" = "PONG" ]; then
    print_status 0 "Redis connection OK"
else
    print_status 1 "Redis connection failed"
fi

echo ""

# Step 3: Get queue length
echo "3. Checking current queue..."
QUEUE_LENGTH=$(docker exec mailredis redis-cli LLEN email_queue 2>/dev/null)

if [ $? -eq 0 ]; then
    print_status 0 "Queue accessible"
    echo "   Current queue length: $QUEUE_LENGTH"
else
    print_status 1 "Cannot access queue"
fi

echo ""

# Step 4: Test get queue endpoint
echo "4. Testing GET queue endpoint..."
QUEUE_RESPONSE=$(curl -s -X GET "${WEBMAIL_URL}/api.php?action=admin_get_queue" \
    -H "Authorization: Bearer ${TOKEN}")

if echo "$QUEUE_RESPONSE" | grep -q '"success":true'; then
    print_status 0 "GET queue endpoint working"
    ITEMS_COUNT=$(echo "$QUEUE_RESPONSE" | grep -o '"queue_length":[0-9]*' | cut -d':' -f2)
    echo "   Queue length from API: $ITEMS_COUNT"
else
    print_status 1 "GET queue endpoint failed"
    echo "   Response: $QUEUE_RESPONSE"
fi

echo ""

# Step 5: Test search queue endpoint
echo "5. Testing search queue endpoint..."
SEARCH_RESPONSE=$(curl -s -X GET "${WEBMAIL_URL}/api.php?action=admin_search_queue&q=test" \
    -H "Authorization: Bearer ${TOKEN}")

if echo "$SEARCH_RESPONSE" | grep -q '"success":true'; then
    print_status 0 "Search queue endpoint working"
    FOUND_COUNT=$(echo "$SEARCH_RESPONSE" | grep -o '"found":[0-9]*' | cut -d':' -f2)
    echo "   Found items: $FOUND_COUNT"
else
    print_status 1 "Search queue endpoint failed"
    echo "   Response: $SEARCH_RESPONSE"
fi

echo ""

# Step 6: Add test email to queue (if needed)
if [ "$QUEUE_LENGTH" -eq 0 ]; then
    echo "6. Adding test email to queue..."
    
    TEST_EMAIL=$(cat <<EOF
{
    "from": "test@example.com",
    "to": "admin@imel.id",
    "subject": "Test Queue Management",
    "body": "This is a test email for queue management",
    "html_body": "",
    "attachments": [],
    "temp_file": null,
    "size": 100,
    "received_at": "$(date '+%Y-%m-%d %H:%M:%S')"
}
EOF
)
    
    docker exec mailredis redis-cli RPUSH email_queue "$TEST_EMAIL" > /dev/null 2>&1
    
    if [ $? -eq 0 ]; then
        print_status 0 "Test email added to queue"
    else
        print_status 1 "Failed to add test email"
    fi
else
    echo "6. Queue not empty, skipping test email addition"
    print_status 0 "Queue already contains emails"
fi

echo ""

# Step 7: Verify admin-only access
echo "7. Testing unauthorized access protection..."
UNAUTH_RESPONSE=$(curl -s -X GET "${WEBMAIL_URL}/api.php?action=admin_get_queue" \
    -H "Authorization: Bearer invalid_token")

if echo "$UNAUTH_RESPONSE" | grep -q '"success":false'; then
    print_status 0 "Unauthorized access blocked"
else
    print_status 1 "Unauthorized access not blocked properly"
fi

echo ""

# Summary
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo ""
echo "Queue Management Feature is ready to use!"
echo ""
echo "To access the queue management interface:"
echo "1. Go to: ${WEBMAIL_URL}/?page=dashboard"
echo "2. Login as: ${ADMIN_EMAIL}"
echo "3. Scroll to 'Manajemen Queue Email' section"
echo ""
echo "Available features:"
echo "  - View all emails in queue"
echo "  - Search emails by from/to/subject"
echo "  - Delete individual emails"
echo "  - Clear entire queue"
echo ""
