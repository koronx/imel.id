<?php

/**
 * Parse email from raw data and extract attachments with full content
 */
function parseEmailFromRaw($rawData) {
    $result = [
        'subject' => '',
        'body' => '',
        'html_body' => '',
        'attachments' => []
    ];
    
    $lines = explode("\r\n", $rawData);
    $headers = [];
    $body = '';
    $inBody = false;
    $contentType = '';
    $boundary = '';
    
    // Parse headers
    foreach ($lines as $line) {
        if (!$inBody) {
            if (empty(trim($line))) {
                $inBody = true;
                continue;
            }
            
            // Capture Subject
            if (preg_match('/^Subject:\s*(.+)$/i', $line, $matches)) {
                $result['subject'] = trim($matches[1]);
            }
            
            // Capture Content-Type and boundary
            if (preg_match('/^Content-Type:\s*(.+)$/i', $line, $matches)) {
                $contentType = trim($matches[1]);
            }
            if (preg_match('/boundary="([^"]+)"/', $line, $boundaryMatch)) {
                $boundary = $boundaryMatch[1];
            } elseif (preg_match('/boundary=([^\s;]+)/', $line, $boundaryMatch)) {
                $boundary = trim($boundaryMatch[1], '"');
            }
        } else {
            $body .= $line . "\r\n";
        }
    }
    
    // If multipart, parse parts
    if (!empty($boundary) && strpos($contentType, 'multipart') !== false) {
        $parts = explode("--" . $boundary, $body);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part) || $part === '--') continue;
            
            // Split part into headers and content
            $partLines = explode("\r\n\r\n", $part, 2);
            if (count($partLines) < 2) continue;
            
            $partHeaders = $partLines[0];
            $partContent = $partLines[1];
            
            // Check if this is nested multipart/alternative
            if (preg_match('/Content-Type:\s*multipart\/alternative.*boundary="([^"]+)"/i', $partHeaders, $nestedBoundaryMatch)) {
                $nestedBoundary = $nestedBoundaryMatch[1];
                $nestedParts = explode("--" . $nestedBoundary, $partContent);
                
                foreach ($nestedParts as $nestedPart) {
                    $nestedPart = trim($nestedPart);
                    if (empty($nestedPart) || $nestedPart === '--') continue;
                    
                    $nestedPartLines = explode("\r\n\r\n", $nestedPart, 2);
                    if (count($nestedPartLines) < 2) continue;
                    
                    $nestedPartHeaders = $nestedPartLines[0];
                    $nestedPartContent = $nestedPartLines[1];
                    
                    if (preg_match('/Content-Type:\s*text\/plain/i', $nestedPartHeaders)) {
                        $decoded = decodeEmailContent($nestedPartContent, $nestedPartHeaders);
                        if (empty($result['body'])) {
                            $result['body'] = $decoded;
                        }
                    } elseif (preg_match('/Content-Type:\s*text\/html/i', $nestedPartHeaders)) {
                        $decoded = decodeEmailContent($nestedPartContent, $nestedPartHeaders);
                        if (empty($result['html_body'])) {
                            $result['html_body'] = $decoded;
                        }
                    }
                }
            }
            // Check content type of this part
            elseif (preg_match('/Content-Type:\s*text\/plain/i', $partHeaders)) {
                $decoded = decodeEmailContent($partContent, $partHeaders);
                if (empty($result['body'])) {
                    $result['body'] = $decoded;
                }
            } elseif (preg_match('/Content-Type:\s*text\/html/i', $partHeaders)) {
                $decoded = decodeEmailContent($partContent, $partHeaders);
                if (empty($result['html_body'])) {
                    $result['html_body'] = $decoded;
                }
            }
            // Check if this is an attachment
            elseif (preg_match('/Content-Type:\s*([^;\r\n]+)/i', $partHeaders, $contentTypeMatch)) {
                $attachmentType = trim($contentTypeMatch[1]);
                
                // Check for filename
                $filename = '';
                if (preg_match('/filename="([^"]+)"/i', $partHeaders, $filenameMatch)) {
                    $filename = $filenameMatch[1];
                } elseif (preg_match('/name="([^"]+)"/i', $partHeaders, $nameMatch)) {
                    $filename = $nameMatch[1];
                }
                
                if (!empty($filename)) {
                    $result['attachments'][] = [
                        'filename' => $filename,
                        'content_type' => $attachmentType,
                        'content' => decodeEmailContent($partContent, $partHeaders)
                    ];
                }
            }
        }
    } else {
        // Not multipart
        $result['body'] = trim($body);
    }
    
    return $result;
}

function decodeEmailContent($content, $headers) {
    // Check for quoted-printable encoding
    if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $headers)) {
        return quoted_printable_decode($content);
    }
    
    // Check for base64 encoding
    if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $headers)) {
        return base64_decode($content);
    }
    
    return $content;
}
