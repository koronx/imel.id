#!/usr/bin/env php
<?php
/**
 * Test DKIM Signing locally
 * Usage: php test_dkim.php
 */

require_once __DIR__ . '/mailserver/src/DKIMSigner.php';

$domain = 'imel.id';
$selector = 'default';
$privateKeyPath = __DIR__ . '/mailserver/dkim/private.key';

// Sample email headers
$headers = [
    'From: admin@imel.id',
    'To: test@example.com',
    'Subject: DKIM Test Email',
    'Date: ' . date('r'),
    'Message-ID: <' . uniqid() . '@imel.id>'
];

// Sample email body
$body = "This is a test email to verify DKIM signing.\r\n\r\nBest regards,\r\nAdmin";

echo "Testing DKIM Signing...\n\n";

echo "Domain: $domain\n";
echo "Selector: $selector\n";
echo "Private Key: " . (file_exists($privateKeyPath) ? 'Found' : 'NOT FOUND') . "\n\n";

if (!file_exists($privateKeyPath)) {
    die("ERROR: Private key not found at $privateKeyPath\n");
}

try {
    $dkimSigner = new DKIMSigner($domain, $selector, $privateKeyPath);
    $signature = $dkimSigner->signMessage($headers, $body);
    
    echo "✅ DKIM Signature Generated Successfully!\n\n";
    echo "DKIM-Signature Header:\n";
    echo str_repeat('=', 80) . "\n";
    echo $signature . "\n";
    echo str_repeat('=', 80) . "\n\n";
    
    // Show full email with DKIM
    echo "Full Email with DKIM:\n";
    echo str_repeat('=', 80) . "\n";
    echo $signature . "\r\n";
    echo implode("\r\n", $headers) . "\r\n\r\n";
    echo $body . "\r\n";
    echo str_repeat('=', 80) . "\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
