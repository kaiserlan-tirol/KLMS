<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use DateTimeImmutable;
use DateTime;
use Psr\Log\LoggerInterface;

class PayPalEmailProcessorService
{
    public function __construct(
        private readonly IncomingPaymentService $incomingPaymentService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Process a PayPal notification email and create an IncomingPayment
     */
    public function processPayPalEmail(array $emailData): ?IncomingPayment
    {
        try {
            $paymentData = $this->parsePayPalEmailContent($emailData['content']);
            
            if (!$paymentData) {
                $this->logger->warning('Could not parse PayPal email', [
                    'subject' => $emailData['subject'] ?? 'unknown',
                    'from' => $emailData['from'] ?? 'unknown'
                ]);
                return null;
            }

            // Log transaction ID for debugging
            $this->logger->info('Extracted transaction ID', [
                'transaction_id' => $paymentData['transaction_id'] ?? 'NOT_EXTRACTED',
                'subject' => $emailData['subject'] ?? 'unknown'
            ]);

            // Check if payment already exists to avoid duplicates
            if (isset($paymentData['transaction_id']) && !empty($paymentData['transaction_id'])) {
                if ($this->paymentAlreadyExists($paymentData['transaction_id'])) {
                    $this->logger->info('PayPal payment already exists - SKIPPING', [
                        'transaction_id' => $paymentData['transaction_id']
                    ]);
                    return null;
                }
            } else {
                $this->logger->warning('No transaction ID found - cannot check for duplicates', [
                    'subject' => $emailData['subject'] ?? 'unknown'
                ]);
            }

            // Create the payment using email received timestamp instead of parsed transaction date
            $emailTimestamp = isset($emailData['timestamp']) ? 
                DateTimeImmutable::createFromFormat('U', (string)$emailData['timestamp']) : 
                ($paymentData['date'] ? new DateTimeImmutable($paymentData['date']) : new DateTimeImmutable());
            
            $this->logger->info('Using email timestamp for payment date', [
                'email_timestamp' => $emailTimestamp->format('Y-m-d H:i:s'),
                'email_timestamp_source' => isset($emailData['timestamp']) ? 'email_received_timestamp' : 'parsed_transaction_date_fallback',
                'parsed_transaction_date' => $paymentData['date'] ?? 'no_date_parsed',
                'parsed_date_success' => ($paymentData['date'] !== null) ? 'success' : 'failed_or_not_found',
                'transaction_id' => $paymentData['transaction_id'] ?? 'not_extracted'
            ]);
            $payment = $this->incomingPaymentService->createPayment(
                $paymentData['amount'],
                $paymentData['currency'],
                $emailTimestamp,
                IncomingPayment::SOURCE_PAYPAL,
                [
                    'externalId' => $paymentData['transaction_id'] ?? '',
                    'transaction_id' => $paymentData['transaction_id'] ?? '',
                    'payerName' => $paymentData['payer_name'] ?? 'Unknown',
                    'payerEmail' => $paymentData['payer_email'] ?? null,
                    'reference' => $paymentData['reference'] ?? '',
                    'raw_email_data' => $emailData
                ]
            );

            $this->logger->info('Created PayPal payment from email', [
                'payment_id' => $payment->getId(),
                'amount' => $paymentData['amount'],
                'transaction_id' => $paymentData['transaction_id'],
                'email_timestamp' => $emailTimestamp->format('Y-m-d H:i:s'),
                'parsed_transaction_date' => $paymentData['date'] ?? 'no_date_parsed',
                'extracted_payer_name' => $paymentData['payer_name'] ?? 'NOT_EXTRACTED',
                'metadata_payer_name' => $payment->getMetadata()['payer_name'] ?? 'NOT_IN_METADATA'
            ]);

            return $payment;

        } catch (\Exception $e) {
            $this->logger->error('Error processing PayPal email', [
                'error' => $e->getMessage(),
                'email_subject' => $emailData['subject'] ?? 'unknown'
            ]);
            return null;
        }
    }

    /**
     * Parse PayPal email content to extract payment information
     */
    public function parsePayPalEmailContent(string $content): ?array
    {
        // Strip HTML tags to get plain text content
        $plainTextContent = $this->cleanHtmlContent($content);
        
        // Use the same logic as extractPaymentDataForDryRun for consistency
        $result = [];
        
        // Extract sender name and amount from the main headline pattern
        // Pattern 1: "Martin Seitl hat Ihnen € 4,00 EUR gesendet"
        if (preg_match('/([A-ZÄÖÜa-zäöüß]+(?:\s+[A-ZÄÖÜa-zäöüß]+)+)\s+hat\s+Ihnen\s+[€]\s*(\d+[.,]\d+)\s*([A-Z]{3})\s+gesendet/iu', $plainTextContent, $matches)) {
            $result['payer_name'] = trim($matches[1]);
            $amount = str_replace(',', '.', $matches[2]);
            $result['amount'] = (float)$amount;
            $result['currency'] = $matches[3] ?? 'EUR';
        }
        // Pattern 2: Look for euro symbol with non-breaking space
        elseif (preg_match('/([A-ZÄÖÜa-zäöüß]+(?:\s+[A-ZÄÖÜa-zäöüß]+)+)\s+hat\s+Ihnen\s+€\s*(\d+[.,]\d+)\s*([A-Z]{3})\s+gesendet/iu', $plainTextContent, $matches)) {
            $result['payer_name'] = trim($matches[1]);
            $amount = str_replace(',', '.', $matches[2]);
            $result['amount'] = (float)$amount;
            $result['currency'] = $matches[3] ?? 'EUR';
        }
        // Pattern 3: "Erhaltener Betrag € 4,00 EUR"
        elseif (preg_match('/Erhaltener\s+Betrag\s+[€]\s*(\d+[.,]\d+)\s*([A-Z]{3})/iu', $plainTextContent, $matches)) {
            $amount = str_replace(',', '.', $matches[1]);
            $result['amount'] = (float)$amount;
            $result['currency'] = $matches[2] ?? 'EUR';
        }
        // Pattern 4: Look for any amount pattern
        elseif (preg_match('/[€]\s*(\d+[.,]\d+)\s*([A-Z]{3})/iu', $plainTextContent, $matches)) {
            $amount = str_replace(',', '.', $matches[1]);
            $result['amount'] = (float)$amount;
            $result['currency'] = $matches[2] ?? 'EUR';
        }
        
        // Extract transaction code from concatenated string "Transaktionscode8FU623121U162273E"
        if (preg_match('/Transaktionscode([A-Z0-9]{16,20})/i', $plainTextContent, $txnMatches)) {
            $result['transaction_id'] = $txnMatches[1];
        }
        // Fallback: try HTML pattern
        elseif (preg_match('/<a[^>]*href="[^"]*details\/([A-Z0-9]{16,20})[^"]*"[^>]*>\s*([A-Z0-9]{16,20})\s*<\/a>/is', $content, $txnMatches)) {
            $result['transaction_id'] = $txnMatches[2];
        }
        
        // Extract sender name if not found yet from "Mitteilung von NAME" pattern
        if (!isset($result['payer_name'])) {
            if (preg_match('/Mitteilung\s+von\s+([A-ZÄÖÜa-zäöüß]+(?:\s+[A-ZÄÖÜa-zäöüß]+)*?)(?:\s+[A-Z]|\s+\d|\s*$)/i', $plainTextContent, $nameMatches)) {
                $result['payer_name'] = trim($nameMatches[1]);
            }
        }
        
        // Extract reference/message - look for text after "Mitteilung von NAME"
        if (isset($result['payer_name'])) {
            $name = preg_quote($result['payer_name'], '/');
            // Pattern: "Mitteilung von Andreas Astl Teck getrränke Sie sehen das Geld"
            if (preg_match('/Mitteilung\s+von\s+' . $name . '\s+([^€]+?)(?:\s+Sie\s+sehen|\s+Gebühr|\s+Summe|\s*$)/i', $plainTextContent, $refMatches)) {
                $reference = trim($refMatches[1]);
                if (!empty($reference) && $reference !== $result['payer_name']) {
                    $result['reference'] = $reference;
                }
            }
            // Alternative pattern: look for message until specific end patterns
            elseif (preg_match('/Mitteilung\s+von\s+' . $name . '\s+([^€]+?)(?:\s+Mehr\s+erfahren|\s+Wie\s+wahrscheinlich|\s+Gebühr|\s+Summe|\s*$)/i', $plainTextContent, $refMatches)) {
                $reference = trim($refMatches[1]);
                if (!empty($reference) && $reference !== $result['payer_name']) {
                    $result['reference'] = $reference;
                }
            }
        }
        
        // Extract date - handle both German formats properly
        if (preg_match('/Transaktionsdatum\s*(\d+\.\s*[A-Za-zäöüß]+\s*\d+)/i', $plainTextContent, $matches)) {
            try {
                // Try to parse German date format like "23. Mai 2025"
                $germanDate = trim($matches[1]);
                $germanToEnglish = [
                    'Januar' => 'January', 'Jänner' => 'January',
                    'Februar' => 'February', 'Feber' => 'February',
                    'März' => 'March', 'April' => 'April', 'Mai' => 'May',
                    'Juni' => 'June', 'Juli' => 'July', 'August' => 'August',
                    'September' => 'September', 'Oktober' => 'October',
                    'November' => 'November', 'Dezember' => 'December'
                ];
                
                $englishDate = str_replace(array_keys($germanToEnglish), array_values($germanToEnglish), $germanDate);
                $timestamp = strtotime($englishDate);
                
                if ($timestamp !== false) {
                    $result['date'] = date('Y-m-d H:i:s', $timestamp);
                } else {
                    $result['date'] = null; // Indicate parsing failed
                }
            } catch (\Exception $e) {
                $result['date'] = null; // Indicate parsing failed
            }
        } elseif (preg_match('/Transaktionsdatum[^>]*>\s*<span>(\d{2}\.\d{2}\.\d{4})<\/span>/i', $plainTextContent, $matches)) {
            // Handle dd.mm.yyyy format like "23.05.2025"
            try {
                $dateStr = $matches[1];
                $timestamp = DateTime::createFromFormat('d.m.Y', $dateStr);
                if ($timestamp !== false) {
                    $result['date'] = $timestamp->format('Y-m-d H:i:s');
                } else {
                    $result['date'] = null; // Indicate parsing failed
                }
            } catch (\Exception $e) {
                $result['date'] = null; // Indicate parsing failed
            }
        } else {
            $result['date'] = null; // No date found
        }

        $result['payer_email'] = '';
        
        // Validate required fields for a payment email
        if (!isset($result['amount']) || (float)$result['amount'] <= 0) {
            return null; // No valid amount found, probably not a payment email
        }

        // Set defaults for missing fields
        if (!isset($result['currency'])) {
            $result['currency'] = 'EUR';
        }
        
        return $result;
    }
    
    /**
     * Extract data directly from HTML content
     */
    private function extractDataFromHtml(string $htmlContent, array $patterns): array
    {
        $result = [];
        
        // Extract amount from headline pattern in HTML
        if (preg_match('/<p[^>]*font-size:42px[^>]*>.*?€\s*(\d+[.,]\d+)\s*([A-Z]{3}).*?<\/p>/is', $htmlContent, $matches)) {
            $amount = str_replace(',', '.', $matches[1]);
            $result['amount'] = (float) $amount;
            $result['currency'] = $matches[2] ?? 'EUR';
        }
        
        // Look for transaction ID in HTML
        if (preg_match('/transaktionscode.*?<a[^>]*>\s*<span>([A-Z0-9]+)<\/span>\s*<\/a>/is', $htmlContent, $matches)) {
            $result['transaction_id'] = $matches[1];
        }
        
        // Look for sender name in payment headline
        if (preg_match('/<p[^>]*font-size:42px[^>]*>.*?([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)\s+hat\s+Ihnen.*?<\/p>/is', $htmlContent, $matches)) {
            $result['payer_name'] = $matches[1];
        }
        
        // Look for reference/message in a table cell
        if (preg_match('/<td[^>]*>\s*<strong>Mitteilung\s+von\s+[^<]+<\/strong><\/td>\s*<td[^>]*[^<]*>([^<]+)<\/td>/is', $htmlContent, $matches)) {
            $result['reference'] = trim($matches[1]);
        }
        
        // Try to extract date
        if (preg_match('/transaktionsdatum.*?<br\s*\/>.*?(\d+\.\s*[A-Za-zäöüß]+\s*\d+)/is', $htmlContent, $matches)) {
            try {
                $result['date'] = date('Y-m-d H:i:s', strtotime($matches[1]));
            } catch (\Exception $e) {
                // If date parsing fails, we'll set a default later
            }
        }
        
        return $result;
    }
    
    /**
     * Extract data from plain text content
     */
    private function extractDataFromPlainText(string $plainTextContent, array $patterns): array
    {
        $result = [];
        
        // Extract amount and currency
        foreach ($patterns['amount'] as $pattern) {
            if (preg_match($pattern, $plainTextContent, $matches)) {
                $amount = str_replace(',', '.', $matches[1]); // Convert German decimal comma to dot
                $amount = preg_replace('/[^\d.]/', '', $amount); // Remove any non-numeric characters except dots
                $result['amount'] = (float) $amount;
                $result['currency'] = $matches[2] ?? 'EUR';
                break;
            }
        }
        
        // Extract transaction ID
        foreach ($patterns['transaction_id'] as $pattern) {
            if (preg_match($pattern, $plainTextContent, $matches)) {
                $result['transaction_id'] = $matches[1];
                break;
            }
        }
        
        // Extract payer email
        foreach ($patterns['payer_email'] as $pattern) {
            if (preg_match($pattern, $plainTextContent, $matches)) {
                $result['payer_email'] = $matches[1];
                break;
            }
        }
        
        // Extract payer name
        foreach ($patterns['payer_name'] as $pattern) {
            if (preg_match($pattern, $plainTextContent, $matches)) {
                $result['payer_name'] = trim($matches[1]);
                break;
            }
        }
        
        // Extract reference/message
        foreach ($patterns['reference'] as $pattern) {
            if (preg_match($pattern, $plainTextContent, $matches)) {
                $result['reference'] = trim($matches[1]);
                break;
            }
        }
        
        // Extract date (fallback to current date if not found)
        foreach ($patterns['date'] as $pattern) {
            if (preg_match($pattern, $plainTextContent, $matches)) {
                try {
                    $result['date'] = date('Y-m-d H:i:s', strtotime($matches[1]));
                } catch (\Exception $e) {
                    // If date parsing fails, we'll set a default later
                }
                break;
            }
        }
        
        return $result;
    }
    
    /**
     * Enhance the result with more focused patterns when initial parsing didn't extract all fields
     */
    private function enhanceResultWithFocusedPatterns(string $plainTextContent, array &$result): void
    {
        // If we're missing the amount, try different patterns
        if (!isset($result['amount'])) {
            // Look for amount in a more context-specific way
            if (preg_match('/erhaltener\s+betrag.*?(\d+[.,]\d+)\s*([A-Z]{3})/i', $plainTextContent, $matches)) {
                $amount = str_replace(',', '.', $matches[1]);
                $result['amount'] = (float) $amount;
                $result['currency'] = $matches[2] ?? 'EUR';
            }
        }
        
        // If we're missing the payer name, try to extract it from context clues
        if (!isset($result['payer_name'])) {
            // Try to extract from "hat Ihnen" pattern in smaller chunks
            if (preg_match('/([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)\s+hat\s+Ihnen/i', $plainTextContent, $matches)) {
                $result['payer_name'] = trim($matches[1]);
            } elseif (preg_match('/hallo\s+([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)/i', $plainTextContent, $matches)) {
                // Recipient name might be useful too
                $result['recipient_name'] = trim($matches[1]);
            }
        }
        
        // Try to extract email from more locations
        if (!isset($result['payer_email']) && preg_match('/paypal-charges@[a-zA-Z0-9.-]+/i', $plainTextContent, $matches)) {
            $result['payer_email'] = $matches[0];
        }
        
        // Try to extract reference from different patterns
        if (!isset($result['reference'])) {
            // Look for descriptions of items purchased
            if (preg_match('/beschreibung.*?<tr>.*?<td[^>]*>([^<]+)<\/td>/is', $plainTextContent, $matches)) {
                $result['reference'] = trim($matches[1]);
            } elseif (preg_match('/mitteilung\s+von.*?(\w+)/i', $plainTextContent, $matches)) {
                $result['reference'] = trim($matches[1]);
            }
        }
    }

    /**
     * Clean HTML content by removing CSS, scripts, and HTML tags
     */
    private function cleanHtmlContent(string $content): string
    {
        // Remove CSS style blocks
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);
        
        // Remove script blocks
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        
        // Remove HTML comments
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        
        // Strip remaining HTML tags
        $content = strip_tags($content);
        
        // Clean up whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        return $content;
    }

    /**
     * Check if a payment with the given transaction ID already exists
     */
    private function paymentAlreadyExists(string $transactionId): bool
    {
        return $this->incomingPaymentService->paymentExistsByExternalId($transactionId);
    }

    /**
     * Validate if an email is a PayPal payment notification
     */
    public function isPayPalPaymentEmail(array $emailData): bool
    {
        $subject = strtolower($emailData['subject'] ?? '');
        $from = strtolower($emailData['from'] ?? '');
        $content = strtolower($emailData['content'] ?? '');

        // Check if it's from PayPal
        $paypalDomains = ['paypal.com', 'paypal.de'];
        $isFromPayPal = false;
        foreach ($paypalDomains as $domain) {
            if (strpos($from, $domain) !== false) {
                $isFromPayPal = true;
                break;
            }
        }

        if (!$isFromPayPal) {
            return false;
        }

        // Check for payment-related keywords in subject
        $paymentKeywords = [
            'payment', 'zahlung', 'geld', 'money', 'received', 'erhalten',
            'transaction', 'transaktion', 'notification', 'benachrichtigung'
        ];

        foreach ($paymentKeywords as $keyword) {
            if (strpos($subject, $keyword) !== false || strpos($content, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract payment data from PayPal email for dry-run display
     * Provides more contextual information for dry-run output
     */
    public function extractPaymentDataForDryRun(array $emailData): ?array
    {
        try {
            // Special handling for "Sie haben eine Zahlung erhalten" emails
            $result = [];
            $plainText = $this->cleanHtmlContent($emailData['content']);
            $htmlContent = $emailData['content']; // Keep raw HTML content for HTML-specific patterns
            
            // DIRECT PATTERN MATCHING for "Sie haben eine Zahlung erhalten" emails
            if ($emailData['subject'] === 'Sie haben eine Zahlung erhalten') {
                // Debug info
                $this->logger->debug('Parsing PayPal payment received email', [
                    'content_preview' => substr($plainText, 0, 1000),
                ]);
                
                // Extract sender name and amount from the main headline pattern
                // Try multiple patterns for amount extraction
                
                // Pattern 1: "Martin Seitl hat Ihnen € 4,00 EUR gesendet"
                if (preg_match('/([A-ZÄÖÜa-zäöüß]+(?:\s+[A-ZÄÖÜa-zäöüß]+)+)\s+hat\s+Ihnen\s+[€]\s*(\d+[.,]\d+)\s*([A-Z]{3})\s+gesendet/iu', $plainText, $matches)) {
                    $result['payer_name'] = trim($matches[1]);
                    $amount = str_replace(',', '.', $matches[2]);
                    $result['amount'] = (float)$amount;
                    $result['currency'] = $matches[3] ?? 'EUR';
                }
                // Pattern 2: Look for euro symbol with non-breaking space
                elseif (preg_match('/([A-ZÄÖÜa-zäöüß]+(?:\s+[A-ZÄÖÜa-zäöüß]+)+)\s+hat\s+Ihnen\s+€\s*(\d+[.,]\d+)\s*([A-Z]{3})\s+gesendet/iu', $plainText, $matches)) {
                    $result['payer_name'] = trim($matches[1]);
                    $amount = str_replace(',', '.', $matches[2]);
                    $result['amount'] = (float)$amount;
                    $result['currency'] = $matches[3] ?? 'EUR';
                }
                // Pattern 3: "Erhaltener Betrag € 4,00 EUR"
                elseif (preg_match('/Erhaltener\s+Betrag\s+[€]\s*(\d+[.,]\d+)\s*([A-Z]{3})/iu', $plainText, $matches)) {
                    $amount = str_replace(',', '.', $matches[1]);
                    $result['amount'] = (float)$amount;
                    $result['currency'] = $matches[2] ?? 'EUR';
                }
                // Pattern 4: Look for any amount pattern
                elseif (preg_match('/[€]\s*(\d+[.,]\d+)\s*([A-Z]{3})/iu', $plainText, $matches)) {
                    $amount = str_replace(',', '.', $matches[1]);
                    $result['amount'] = (float)$amount;
                    $result['currency'] = $matches[2] ?? 'EUR';
                }
                
                // Extract transaction code from concatenated string "Transaktionscode8FU623121U162273E"
                if (preg_match('/Transaktionscode([A-Z0-9]{16,20})/i', $plainText, $txnMatches)) {
                    $result['transaction_id'] = $txnMatches[1];
                }
                // Fallback: try HTML pattern
                elseif (preg_match('/<a[^>]*href="[^"]*details\/([A-Z0-9]{16,20})[^"]*"[^>]*>\s*([A-Z0-9]{16,20})\s*<\/a>/is', $htmlContent, $txnMatches)) {
                    $result['transaction_id'] = $txnMatches[2];
                }
                
                // Extract sender name if not found yet from "Mitteilung von NAME" pattern
                if (!isset($result['payer_name'])) {
                    if (preg_match('/Mitteilung\s+von\s+([A-ZÄÖÜa-zäöüß]+(?:\s+[A-ZÄÖÜa-zäöüß]+)*?)(?:\s+[A-Z]|\s+\d|\s*$)/i', $plainText, $nameMatches)) {
                        $result['payer_name'] = trim($nameMatches[1]);
                    }
                }
                
                // Extract reference/message - look for text after "Mitteilung von NAME"
                if (isset($result['payer_name'])) {
                    $name = preg_quote($result['payer_name'], '/');
                    // Pattern: "Mitteilung von Andreas Astl Teck getrränke Sie sehen das Geld"
                    if (preg_match('/Mitteilung\s+von\s+' . $name . '\s+([^€]+?)(?:\s+Sie\s+sehen|\s+Gebühr|\s+Summe|\s*$)/i', $plainText, $refMatches)) {
                        $reference = trim($refMatches[1]);
                        if (!empty($reference) && $reference !== $result['payer_name']) {
                            $result['reference'] = $reference;
                        }
                    }
                    // Alternative pattern: look for message until specific end patterns
                    elseif (preg_match('/Mitteilung\s+von\s+' . $name . '\s+([^€]+?)(?:\s+Mehr\s+erfahren|\s+Wie\s+wahrscheinlich|\s+Gebühr|\s+Summe|\s*$)/i', $plainText, $refMatches)) {
                        $reference = trim($refMatches[1]);
                        if (!empty($reference) && $reference !== $result['payer_name']) {
                            $result['reference'] = $reference;
                        }
                    }
                }
                
                // If payer name not found yet, try extracting from other patterns
                if (!isset($result['payer_name'])) {
                    // Try the "Mitteilung von X" pattern in HTML
                    if (preg_match('/<td[^>]*>\s*<strong>Mitteilung\s+von\s+([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)/is', $htmlContent, $nameMatches)) {
                        $result['payer_name'] = $nameMatches[1];
                    } 
                    // Try plain text version
                    elseif (preg_match('/Mitteilung\s+von\s+([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)/i', $plainText, $nameMatches)) {
                        $result['payer_name'] = $nameMatches[1];
                    }
                    // Try extracting from preHeader
                    elseif (preg_match('/<h4[^>]*preHeader[^>]*>.*?([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+).*?<\/h4>/is', $htmlContent, $nameMatches)) {
                        $result['payer_name'] = $nameMatches[1];
                    }
                }
            } else {
                // For non-"Sie haben eine Zahlung erhalten" emails, use the general parser
                $result = $this->parsePayPalEmailContent($emailData['content']);
            }
            
            // Set defaults and add additional context regardless of how the email was parsed
            if (!empty($result)) {
                // Add subject
                $result['subject'] = $emailData['subject'] ?? 'Unknown subject';
                
                // Set email from sender if not extracted
                if (!isset($result['payer_email'])) {
                    // Try to extract from HTML first
                    if (preg_match('/<a[^>]*>([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})<\/a>/i', $htmlContent, $emailMatches)) {
                        $result['payer_email'] = $emailMatches[1];
                    }
                    // Fallback: Use from field as last resort
                    elseif (isset($emailData['from'])) {
                        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $emailData['from'], $fromMatches)) {
                            $result['payer_email'] = $fromMatches[1];
                        } else {
                            $result['payer_email'] = $emailData['from'];
                        }
                    }
                }
                
                // Set date from email timestamp or current date as fallback
                if (!isset($result['date']) && isset($emailData['timestamp'])) {
                    $result['date'] = date('Y-m-d H:i:s', $emailData['timestamp']);
                } elseif (!isset($result['date'])) {
                    $result['date'] = date('Y-m-d H:i:s');
                }
                
                // Handle non-breaking spaces in amount (common in HTML emails)
                if (isset($result['amount']) && is_string($result['amount'])) {
                    $result['amount'] = (float)str_replace(',', '.', $result['amount']);
                }
                
                // Make sure currency is set
                if (!isset($result['currency'])) {
                    $result['currency'] = 'EUR';
                }
            }
            
            // Last resort fallback if we couldn't extract complete information but we know it's a payment email
            if ((empty($result) || !isset($result['amount'])) && 
                ($emailData['subject'] === 'Sie haben eine Zahlung erhalten')) {
                
                // Look for any amount pattern in plain text
                if (preg_match('/€\s*(\d+[.,]\d+)\s*([A-Z]{3})/i', $plainText, $amountMatches)) {
                    $result['amount'] = (float)str_replace(',', '.', $amountMatches[1]);
                    $result['currency'] = $amountMatches[2] ?? 'EUR';
                    $result['subject'] = $emailData['subject'];
                    
                    // Default sender email to the from address
                    if (isset($emailData['from'])) {
                        $result['payer_email'] = $emailData['from'];
                    }
                    
                    // Default date to the email timestamp
                    if (isset($emailData['timestamp'])) {
                        $result['date'] = date('Y-m-d H:i:s', $emailData['timestamp']);
                    }
                }
            }
            
            return !empty($result) ? $result : null;
        } catch (\Exception $e) {
            $this->logger->warning('Could not extract payment data for dry-run', [
                'error' => $e->getMessage(),
                'subject' => $emailData['subject'] ?? 'unknown'
            ]);
            return null;
        }
    }
    
    /**
     * Special parser for "Sie haben eine Zahlung erhalten" emails
     * This handles the specific format seen in the mock data
     */
    private function extractPayPalPaymentReceived(string $plainText): ?array
    {
        $result = [];
        
        // Looking for the exact pattern in the mock data: "Martin Seitl hat Ihnen € 4,00 EUR gesendet"
        if (preg_match('/([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)\s+hat\s+[Ii]hnen\s+€\s*(\d+[.,]\d+)\s*([A-Z]{3})\s+gesendet/i', $plainText, $matches)) {
            $result['payer_name'] = $matches[1];
            $amount = str_replace(',', '.', $matches[2]); 
            $result['amount'] = (float) $amount;
            $result['currency'] = $matches[3] ?? 'EUR';
        }
        // Backup pattern: "Erhaltener Betrag € 4,00 EUR"
        elseif (preg_match('/Erhaltener\s+Betrag\s+€\s*(\d+[.,]\d+)\s*([A-Z]{3})/i', $plainText, $matches)) {
            $amount = str_replace(',', '.', $matches[1]); 
            $result['amount'] = (float) $amount;
            $result['currency'] = $matches[2] ?? 'EUR';
        }
        
        // Extract transaction ID - exact format from debug output
        if (preg_match('/Transaktionscode\s*([A-Z0-9]+)/i', $plainText, $matches)) {
            $result['transaction_id'] = $matches[1];
        }
        
        // Extract date
        if (preg_match('/Transaktionsdatum\s*(\d+\.\s*[A-Za-zäöüß]+\s*\d+)/i', $plainText, $matches)) {
            try {
                $result['date'] = date('Y-m-d H:i:s', strtotime($matches[1]));
            } catch (\Exception $e) {
                $result['date'] = date('Y-m-d H:i:s');
            }
        }
        
        // If we couldn't find the payer name from the "hat Ihnen" pattern, try others
        if (!isset($result['payer_name'])) {
            // Try the "Mitteilung von X" pattern
            if (preg_match('/Mitteilung\s+von\s+([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)/i', $plainText, $matches)) {
                $result['payer_name'] = $matches[1];
            }
        }
        
        // Extract message/reference - exact patterns from debug output
        if (preg_match('/Mitteilung\s+von\s+[A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+\s+([A-Za-zäöüß0-9\s]+?)(?:\s+Gebühr|\s+Summe|$)/i', $plainText, $matches)) {
            $result['reference'] = trim($matches[1]);
        } elseif (preg_match('/Bestellnummer\s+(\d+)/i', $plainText, $matches)) {
            $result['reference'] = 'Bestellnummer ' . $matches[1];
        }
        
        // If we found a transaction ID but couldn't get name or amount, try more aggressive patterns
        if (isset($result['transaction_id']) && (!isset($result['payer_name']) || !isset($result['amount']))) {
            // Check for transaction ID and try to map sender from surrounding context
            $txnId = $result['transaction_id'];
            
            // Look specifically for the structure in the debug output
            if (preg_match('/Hallo\s+[A-Za-zäöüß\s]+!\s+([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)\s+hat\s+[Ii]hnen\s+€\s*(\d+[.,]\d+)\s*([A-Z]{3})/i', $plainText, $matches)) {
                $result['payer_name'] = $matches[1];
                $amount = str_replace(',', '.', $matches[2]); 
                $result['amount'] = (float) $amount;
                $result['currency'] = $matches[3] ?? 'EUR';
            }
            
            // Extract reference directly after sender name
            if (isset($result['payer_name'])) {
                $name = preg_quote($result['payer_name'], '/');
                if (preg_match('/Mitteilung\s+von\s+' . $name . '\s+([A-Za-zäöüß0-9\s]+?)(?:\s+Gebühr|\s+Summe|$)/i', $plainText, $matches)) {
                    $result['reference'] = trim($matches[1]);
                }
            }
        }
        
        // If we have at least a transaction ID and amount, this is likely a valid payment
        if (isset($result['transaction_id']) && isset($result['amount'])) {
            return $result;
        }
        
        // Last ditch effort - if it has "Sie haben eine Zahlung erhalten" and some amount, try to make a minimal valid result
        if (strpos($plainText, 'Sie haben eine Zahlung erhalten') !== false) {
            if (preg_match('/€\s*(\d+[.,]\d+)\s*([A-Z]{3})/i', $plainText, $matches)) {
                $amount = str_replace(',', '.', $matches[1]); 
                $result['amount'] = (float) $amount;
                $result['currency'] = $matches[2] ?? 'EUR';
                
                // Try one more time for payer
                if (preg_match('/([A-Z][a-zäöüß]+\s+[A-Z][a-zäöüß]+)\s+hat/i', $plainText, $matches)) {
                    $result['payer_name'] = $matches[1];
                }
                
                // If we have amount, it's probably a payment
                return $result;
            }
        }
        
        return null;
    }
}
