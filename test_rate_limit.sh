#!/bin/bash

# Test Rate Limiting Feature
# Script ini untuk menguji fitur rate limiting email eksternal

echo "==================================="
echo "Test Rate Limiting Email Eksternal"
echo "==================================="
echo ""

# Function to check rate limit status for a user
check_rate_limit() {
    local email=$1
    echo "Checking rate limit for: $email"
    docker-compose exec -T database psql -U mailuser -d maildb << EOF
SELECT 
    u.email,
    COUNT(el.id) as emails_sent_last_hour,
    10 - COUNT(el.id) as remaining_quota
FROM users u
LEFT JOIN external_email_log el ON u.id = el.user_id 
    AND el.sent_at > NOW() - INTERVAL '1 hour'
WHERE u.email = '$email'
GROUP BY u.id, u.email;
EOF
    echo ""
}

# Function to show recent external emails
show_recent_emails() {
    echo "Recent external emails (last hour):"
    docker-compose exec -T database psql -U mailuser -d maildb << EOF
SELECT 
    u.email as sender,
    el.to_email as recipient,
    el.sent_at,
    EXTRACT(EPOCH FROM (NOW() - el.sent_at))/60 as minutes_ago
FROM external_email_log el
JOIN users u ON el.user_id = u.id
WHERE el.sent_at > NOW() - INTERVAL '1 hour'
ORDER BY el.sent_at DESC
LIMIT 20;
EOF
    echo ""
}

# Function to simulate sending external email
simulate_send() {
    local email=$1
    local recipient=$2
    
    echo "Simulating email send from $email to $recipient"
    docker-compose exec -T database psql -U mailuser -d maildb << EOF
INSERT INTO external_email_log (user_id, to_email, sent_at)
SELECT id, '$recipient', NOW()
FROM users
WHERE email = '$email';
EOF
    echo "Email logged!"
    echo ""
}

# Function to clear rate limit for testing
clear_rate_limit() {
    local email=$1
    echo "Clearing rate limit for: $email"
    docker-compose exec -T database psql -U mailuser -d maildb << EOF
DELETE FROM external_email_log
WHERE user_id IN (SELECT id FROM users WHERE email = '$email');
EOF
    echo "Rate limit cleared!"
    echo ""
}

# Main menu
case "${1:-menu}" in
    check)
        check_rate_limit "${2:-admin@imel.id}"
        ;;
    recent)
        show_recent_emails
        ;;
    simulate)
        if [ -z "$2" ] || [ -z "$3" ]; then
            echo "Usage: $0 simulate <from_email> <to_email>"
            echo "Example: $0 simulate admin@imel.id test@gmail.com"
            exit 1
        fi
        simulate_send "$2" "$3"
        check_rate_limit "$2"
        ;;
    clear)
        clear_rate_limit "${2:-admin@imel.id}"
        ;;
    test)
        echo "Running automatic test..."
        echo ""
        
        # Clear existing data
        clear_rate_limit "admin@imel.id"
        
        # Send 10 emails (should work)
        echo "Step 1: Sending 10 emails (should succeed)..."
        for i in {1..10}; do
            simulate_send "admin@imel.id" "test${i}@gmail.com"
            echo "Email $i sent"
        done
        
        check_rate_limit "admin@imel.id"
        
        echo "Step 2: Checking if limit is reached..."
        show_recent_emails
        
        echo ""
        echo "Test complete! Now try sending an email via webmail to test the actual limit."
        echo "It should show an error message."
        ;;
    *)
        echo "Usage: $0 {check|recent|simulate|clear|test} [args]"
        echo ""
        echo "Commands:"
        echo "  check [email]              - Check rate limit for user (default: admin@imel.id)"
        echo "  recent                     - Show recent external emails"
        echo "  simulate <from> <to>       - Simulate sending an email"
        echo "  clear [email]              - Clear rate limit for user"
        echo "  test                       - Run automatic test"
        echo ""
        echo "Examples:"
        echo "  $0 check admin@imel.id"
        echo "  $0 simulate admin@imel.id test@gmail.com"
        echo "  $0 clear admin@imel.id"
        echo "  $0 test"
        ;;
esac
