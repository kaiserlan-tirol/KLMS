<?php

namespace App\Service;

use DateTime;
use Psr\Log\LoggerInterface;

class EmailFetcherService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption = 'ssl',
        private readonly string $folder = 'INBOX'
    ) {}

    /**
     * Fetch emails since a specific date (with optional mock data for debug)
     */
    public function fetchEmailsSince(DateTime $since, int $limit = 100, ?string $fromFilter = null, bool $debug = false): array
    {
        // Return mock data in debug mode
        if ($debug) {
            return $this->getMockEmails($since, $limit, $fromFilter);
        }

        $emails = [];

        try {
            $connection = $this->connectToImap();
            
            if (!$connection) {
                throw new \Exception('Could not connect to IMAP server');
            }

            // Select INBOX or specified folder
            $folderPath = $this->buildImapConnectionString() . $this->folder;
            if (!imap_reopen($connection, $folderPath)) {
                throw new \Exception('Could not select folder: ' . $this->folder);
            }

            // Build search criteria
            $searchCriteria = 'SINCE "' . $since->format('d-M-Y') . '"';
            
            if ($fromFilter) {
                $searchCriteria .= ' FROM "' . $fromFilter . '"';
            }

            $this->logger->info('Searching emails with criteria: ' . $searchCriteria);

            // Search for emails
            $messageNumbers = imap_search($connection, $searchCriteria);

            if (!$messageNumbers) {
                $this->logger->info('No emails found matching criteria');
                imap_close($connection);
                return [];
            }

            // Limit results
            $messageNumbers = array_slice($messageNumbers, 0, $limit);

            $this->logger->info(sprintf('Found %d emails to process', count($messageNumbers)));

            foreach ($messageNumbers as $messageNumber) {
                try {
                    $email = $this->parseEmail($connection, $messageNumber);
                    if ($email) {
                        $emails[] = $email;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error parsing email: ' . $e->getMessage());
                }
            }

            imap_close($connection);

        } catch (\Exception $e) {
            $this->logger->error('Error fetching emails: ' . $e->getMessage());
            throw $e;
        }

        return $emails;
    }

    /**
     * Connect to IMAP server
     */
    private function connectToImap()
    {
        if (!extension_loaded('imap')) {
            throw new \Exception('IMAP extension is not installed. Please install php-imap extension.');
        }
        
        $connectionString = $this->buildImapConnectionString();
        
        $this->logger->info('Connecting to IMAP server: ' . $this->host);

        $connection = imap_open($connectionString, $this->username, $this->password);

        if (!$connection) {
            $error = imap_last_error();
            $this->logger->error('IMAP connection failed: ' . ($error ?? 'Unknown error'));
            return false;
        }

        return $connection;
    }

    /**
     * Build IMAP connection string
     */
    private function buildImapConnectionString(): string
    {
        $flags = '';
        if ($this->encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($this->encryption === 'tls') {
            $flags .= '/tls';
        }
        $flags .= '/novalidate-cert'; // You might want to remove this in production

        return sprintf('{%s:%d%s}', $this->host, $this->port, $flags);
    }

    /**
     * Parse email message
     */
    private function parseEmail($connection, int $messageNumber): ?array
    {
        try {
            // Get email header
            $header = imap_headerinfo($connection, $messageNumber);
            
            if (!$header) {
                return null;
            }

            // Get email body
            $body = imap_body($connection, $messageNumber);
            
            // Get email structure for better parsing
            $structure = imap_fetchstructure($connection, $messageNumber);

            $email = [
                'message_number' => $messageNumber,
                'subject' => $this->decodeHeaderText($header->subject ?? ''),
                'from' => $this->extractEmailFromHeader($header->from ?? []),
                'to' => $this->extractEmailFromHeader($header->to ?? []),
                'date' => $header->date ?? '',
                'timestamp' => $header->udate ?? time(),
                'content' => $this->decodeEmailBody($body, $structure),
                'raw_header' => $header,
                'raw_structure' => $structure
            ];

            return $email;

        } catch (\Exception $e) {
            $this->logger->error('Error parsing email: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Decode email header text
     */
    private function decodeHeaderText(string $text): string
    {
        $decoded = imap_mime_header_decode($text);
        $result = '';
        
        foreach ($decoded as $part) {
            $result .= $part->text;
        }

        return $result;
    }

    /**
     * Extract email address from header
     */
    private function extractEmailFromHeader(array $addresses): string
    {
        if (empty($addresses)) {
            return '';
        }

        $address = $addresses[0];
        $email = '';

        if (isset($address->mailbox) && isset($address->host)) {
            $email = $address->mailbox . '@' . $address->host;
        }

        return $email;
    }

    /**
     * Decode email body
     */
    private function decodeEmailBody(string $body, $structure): string
    {
        // Basic decoding - you might need to enhance this for complex email structures
        
        if (isset($structure->encoding)) {
            switch ($structure->encoding) {
                case 1: // 8bit
                    $body = imap_8bit($body);
                    break;
                case 2: // binary
                    $body = imap_binary($body);
                    break;
                case 3: // base64
                    $body = base64_decode($body);
                    break;
                case 4: // quoted-printable
                    $body = quoted_printable_decode($body);
                    break;
            }
        }

        return $body;
    }

    /**
     * Test IMAP connection
     */
    public function testConnection(): bool
    {
        try {
            $connection = $this->connectToImap();
            if ($connection) {
                imap_close($connection);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->logger->error('IMAP connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate mock email data for debugging
     */
    private function getMockEmails(DateTime $since, int $limit, ?string $fromFilter): array
    {
        $mockEmails = [];
        
        // Mock PayPal payment emails
        if ($fromFilter === 'service@paypal.at' || $fromFilter === null) {
            // Mock email 1 - Mathias Resienhofer payment
            $mockEmails[] = [
                'message_number' => 1,
                'subject' => 'Sie haben eine Zahlung erhalten',
                'from' => 'service@paypal.at',
                'to' => 'markus@kaiserlan.at',
                'date' => $since->format('D, d M Y H:i:s O'),
                'timestamp' => $since->getTimestamp(),
                'content' => 'Sie haben eine Zahlung erhalten Markus Edenhauser, Sie haben € 30,00 EUR erhalten Hallo Markus Edenhauser! Mathias Resienhofer hat Ihnen € 30,00 EUR gesendet Mitteilung von Mathias Resienhofer: Skullker Transaktionsdetails Transaktionscode0M885107LG310593G Transaktionsdatum22. Jänner 2025 Erhaltener Betrag € 30,00 EUR Sie sehen das Geld nicht in Ihrem Konto? Keine Sorge – oft dauert das nur einige Minuten.',
                'raw_header' => null,
                'raw_structure' => null
            ];
            
            // Mock email 2 - Anna Schmidt payment with email address
            $mockEmails[] = [
                'message_number' => 2,
                'subject' => 'Sie haben eine Zahlung erhalten',
                'from' => 'service@paypal.at',
                'to' => 'markus@kaiserlan.at',
                'date' => $since->format('D, d M Y H:i:s O'),
                'timestamp' => $since->getTimestamp(),
                'content' => 'Sie haben eine Zahlung erhalten Markus Edenhauser, Sie haben € 15,50 EUR erhalten Hallo Markus Edenhauser! Anna Schmidt (anna.schmidt@example.com) hat Ihnen € 15,50 EUR gesendet Mitteilung von Anna Schmidt: Party Beitrag Transaktionsdetails Transaktionscode9AB123456C789012D Transaktionsdatum23. Mai 2025 Erhaltener Betrag € 15,50 EUR',
                'raw_header' => null,
                'raw_structure' => null
            ];
            
            // Mock email 3 - Julian Mayer payment with different format
            $mockEmails[] = [
                'message_number' => 3,
                'subject' => 'Sie haben eine Zahlung erhalten',
                'from' => 'service@paypal.at',
                'to' => 'markus@kaiserlan.at',
                'date' => $since->format('D, d M Y H:i:s O'),
                'timestamp' => $since->getTimestamp(),
                'content' => 'Sie haben eine Zahlung erhalten Markus Edenhauser, Sie haben € 42,99 EUR erhalten Hallo Markus Edenhauser! Julian Mayer (julian.mayer@example.com) hat Ihnen € 42,99 EUR gesendet. Mitteilung von Julian Mayer: Teambuilding Mai 2025 Transaktionsdetails: Transaktionscode: 5TY789012GH345678J Transaktionsdatum: 15. Mai 2025 Erhaltener Betrag: € 42,99 EUR',
                'raw_header' => null,
                'raw_structure' => null
            ];
            
            // Mock email 4 - Sarah Huber payment with HTML formatting
            $mockEmails[] = [
                'message_number' => 4,
                'subject' => 'Sie haben eine Zahlung erhalten',
                'from' => 'service@paypal.at',
                'to' => 'markus@kaiserlan.at',
                'date' => $since->format('D, d M Y H:i:s O'),
                'timestamp' => $since->getTimestamp(),
                'content' => '<html><body><h2>Sie haben eine Zahlung erhalten</h2><p>Markus Edenhauser, Sie haben € 25,00 EUR erhalten</p><p>Hallo Markus Edenhauser!</p><p>Sarah Huber (sarah.h@example.com) hat Ihnen € 25,00 EUR gesendet.</p><p>Mitteilung von Sarah Huber: Mitgliedsbeitrag Kaiserlan</p><h3>Transaktionsdetails:</h3><ul><li>Transaktionscode: ABC123XYZ456789</li><li>Transaktionsdatum: 18. Mai 2025</li><li>Erhaltener Betrag: € 25,00 EUR</li></ul></body></html>',
                'raw_header' => null,
                'raw_structure' => null
            ];

            // Mock email 5 - Thomas Bauer payment with no message
            $mockEmails[] = [
                'message_number' => 5,
                'subject' => 'Sie haben eine Zahlung erhalten',
                'from' => 'service@paypal.at',
                'to' => 'markus@kaiserlan.at',
                'date' => $since->format('D, d M Y H:i:s O'),
                'timestamp' => $since->getTimestamp(),
                'content' => 'Sie haben eine Zahlung erhalten Markus Edenhauser, Sie haben € 10,00 EUR erhalten Hallo Markus Edenhauser! Thomas Bauer hat Ihnen € 10,00 EUR gesendet. Transaktionsdetails: Transaktionscode: TBZ987654AB321 Transaktionsdatum: 20. Mai 2025 Erhaltener Betrag: € 10,00 EUR',
                'raw_header' => null,
                'raw_structure' => null
            ];
        }
        
        // Add some non-payment emails to test filtering
        $subjects = [
            'PayPal: Ihre Kontoübersicht', 
            'Wichtige Änderungen bei PayPal', 
            'Sicherheitshinweis zu Ihrem PayPal-Konto',
            'Bestätigung Ihrer PayPal-Zahlung',
            'PayPal Newsletter Mai 2025',
            'Einladung zum PayPal Business Webinar',
            'Ihr PayPal-Konto wurde eingeschränkt',
            'Erhöhen Sie die Sicherheit Ihres PayPal-Kontos'
        ];
        
        for ($i = 0; $i < count($subjects); $i++) {
            $mockEmails[] = [
                'message_number' => 100 + $i,
                'subject' => $subjects[$i],
                'from' => $fromFilter ?? 'info@paypal.at',
                'to' => 'markus@kaiserlan.at',
                'date' => $since->format('D, d M Y H:i:s O'),
                'timestamp' => $since->getTimestamp(),
                'content' => 'This is a newsletter email about PayPal updates and features.',
                'raw_header' => null,
                'raw_structure' => null
            ];
        }
        
        // Shuffle to make it more realistic
        shuffle($mockEmails);
        
        // Filter by fromFilter explicitly if set
        if ($fromFilter) {
            $mockEmails = array_filter($mockEmails, function($email) use ($fromFilter) {
                return $email['from'] === $fromFilter;
            });
            $mockEmails = array_values($mockEmails); // Reset array keys
        }
        
        // Apply limit
        return array_slice($mockEmails, 0, $limit);
    }
}
