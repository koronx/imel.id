<?php

class DKIMSigner {
    private $domain;
    private $selector;
    private $privateKey;
    
    public function __construct($domain, $selector, $privateKeyPath) {
        $this->domain = $domain;
        $this->selector = $selector;
        $this->privateKey = file_get_contents($privateKeyPath);
        
        if (!$this->privateKey) {
            throw new Exception("Failed to load DKIM private key");
        }
    }
    
    /**
     * Sign email message with DKIM
     */
    public function signMessage($headers, $body) {
        // Canonicalize body (simple canonicalization)
        $canonicalizedBody = $this->canonicalizeBody($body);
        
        // Calculate body hash
        $bodyHash = base64_encode(hash('sha256', $canonicalizedBody, true));
        
        // Prepare DKIM signature header
        $dkimHeader = $this->prepareDKIMHeader($bodyHash, $headers);
        
        // Sign the header
        $signature = $this->signHeader($dkimHeader, $headers);
        
        // Build final DKIM-Signature header
        $dkimSignature = "DKIM-Signature: " . $dkimHeader . "b=" . $signature;
        
        return $dkimSignature;
    }
    
    /**
     * Canonicalize body (simple)
     */
    private function canonicalizeBody($body) {
        // Remove trailing empty lines
        $body = rtrim($body, "\r\n");
        
        // Ensure body ends with CRLF
        if (!empty($body)) {
            $body .= "\r\n";
        }
        
        return $body;
    }
    
    /**
     * Prepare DKIM header (without signature)
     */
    private function prepareDKIMHeader($bodyHash, $headers) {
        $time = time();
        
        // Headers to sign (lowercase for h= parameter)
        $headersToSign = ['from', 'to', 'subject', 'date', 'message-id'];
        $headerList = implode(':', $headersToSign);
        
        $dkim = "v=1; a=rsa-sha256; c=relaxed/simple; d={$this->domain}; " .
                "s={$this->selector}; t={$time}; " .
                "bh={$bodyHash}; " .
                "h={$headerList}; " .
                "b=";
        
        return $dkim;
    }
    
    /**
     * Sign header
     */
    private function signHeader($dkimHeader, $headers) {
        // Canonicalize headers
        $canonicalizedHeaders = $this->canonicalizeHeaders($headers);
        
        // Canonicalize DKIM header (includes dkim-signature: prefix)
        $canonicalizedDkimHeader = $this->canonicalizeDKIMHeader($dkimHeader);
        
        // Data to sign
        $dataToSign = $canonicalizedHeaders . $canonicalizedDkimHeader;
        
        // Sign with private key
        $privateKeyResource = openssl_pkey_get_private($this->privateKey);
        if (!$privateKeyResource) {
            throw new Exception("Failed to load private key: " . openssl_error_string());
        }
        
        $signature = '';
        openssl_sign($dataToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKeyResource);
        
        return base64_encode($signature);
    }
    
    /**
     * Canonicalize headers (relaxed)
     */
    private function canonicalizeHeaders($headers) {
        $result = '';
        $headersToSign = ['From', 'To', 'Subject', 'Date', 'Message-ID'];
        
        foreach ($headersToSign as $headerName) {
            $headerNameLower = strtolower($headerName);
            
            // Find header in message
            foreach ($headers as $header) {
                if (stripos($header, $headerName . ':') === 0) {
                    // Extract header value
                    $parts = explode(':', $header, 2);
                    $value = isset($parts[1]) ? trim($parts[1]) : '';
                    
                    // Canonicalize: lowercase header name, single space after colon, trim value
                    $result .= $headerNameLower . ':' . preg_replace('/\s+/', ' ', $value) . "\r\n";
                    break;
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Canonicalize DKIM header (relaxed)
     */
    private function canonicalizeDKIMHeader($dkimHeader) {
        // For relaxed canonicalization:
        // - Convert header name to lowercase
        // - Unfold header (remove line breaks)
        // - Compress whitespace before/after =
        // - Remove trailing spaces
        $canonicalized = 'dkim-signature:' . preg_replace('/\s+/', ' ', trim($dkimHeader));
        $canonicalized = preg_replace('/\s*=\s*/', '=', $canonicalized);
        $canonicalized = preg_replace('/\s*;\s*/', ';', $canonicalized);
        
        return rtrim($canonicalized);
    }
}
