#!/bin/bash

# Test Rate Limiting di Mail Worker
# Script ini untuk menguji fitur rate limiting di mail worker

echo "============================================"
echo "Test Rate Limiting Email di Mail Worker"
echo "============================================"
echo ""

# Function to push email to queue
push_email_to_queue() {
    local from=$1
    local to=$2
    local subject=$3
    
    echo "Pushing email to queue:"
    echo "  From: $from"
    echo "  To: $to"
    echo "  Subject: $subject"
    
    docker-compose exec -T redis redis-cli << EOF
RPUSH email_queue '{"from":"$from","to":"$to","subject":"$subject","body":"Test email body","html_body":"","attachments":[],"received_at":"$(date '+%Y-%m-%d %H:%M:%S')"}'
EOF
    
    echo "Email pushed to queue!"
    echo ""
}

# Function to check queue size
check_queue_size() {
    echo "Current queue size:"
    docker-compose exec -T redis redis-cli LLEN email_queue
    echo ""
}

# Function to clear queue
clear_queue() {
    echo "Clearing email queue..."
    docker-compose exec -T redis redis-cli DEL email_queue
    echo "Queue cleared!"
    echo ""
}

# Function to check worker logs
check_worker_logs() {
    echo "Recent worker logs (last 30 lines):"
    docker-compose logs --tail=30 mail-worker
    echo ""
}

# Function to test rate limit
test_rate_limit() {
    echo "Running rate limit test..."
    echo ""
    
    # Clear existing rate limit
    ./test_rate_limit.sh clear admin@imel.id
    
    # Clear queue
    clear_queue
    
    echo "Step 1: Pushing 11 emails to queue (1 more than limit)..."
    for i in {1..11}; do
        push_email_to_queue "admin@imel.id" "test${i}@gmail.com" "Test Email ${i}"
        sleep 0.5
    done
    
    check_queue_size
    
    echo "Step 2: Wait for worker to process (15 seconds)..."
    sleep 15
    
    echo "Step 3: Check rate limit status..."
    ./test_rate_limit.sh check admin@imel.id
    
    echo "Step 4: Check worker logs for rate limit messages..."
    check_worker_logs | grep -i "rate limit"
    
    echo ""
    echo "Test complete!"
    echo "Expected: 10 emails should be sent, 1 should be blocked by rate limit"
}

# Main menu
case "${1:-menu}" in
    push)
        if [ -z "$2" ] || [ -z "$3" ]; then
            echo "Usage: $0 push <from_email> <to_email> [subject]"
            echo "Example: $0 push admin@imel.id test@gmail.com 'Test Subject'"
            exit 1
        fi
        push_email_to_queue "$2" "$3" "${4:-Test Email}"
        check_queue_size
        ;;
    queue)
        check_queue_size
        ;;
    clear)
        clear_queue
        ;;
    logs)
        check_worker_logs
        ;;
    test)
        test_rate_limit
        ;;
    *)
        echo "Usage: $0 {push|queue|clear|logs|test}"
        echo ""
        echo "Commands:"
        echo "  push <from> <to> [subject]  - Push email to queue"
        echo "  queue                       - Check queue size"
        echo "  clear                       - Clear queue"
        echo "  logs                        - Show worker logs"
        echo "  test                        - Run automatic rate limit test"
        echo ""
        echo "Examples:"
        echo "  $0 push admin@imel.id test@gmail.com 'Hello'"
        echo "  $0 queue"
        echo "  $0 clear"
        echo "  $0 logs"
        echo "  $0 test"
        ;;
esac
