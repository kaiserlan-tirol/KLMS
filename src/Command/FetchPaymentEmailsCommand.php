<?php

namespace App\Command;

use App\Service\IncomingPaymentService;
use App\Service\PayPalImportService;
use App\Service\EmailFetcherService;
use App\Service\PayPalEmailProcessorService;
use DateTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-payment-emails',
    description: 'Fetch and process payment emails since a specific date'
)]
class FetchPaymentEmailsCommand extends Command
{
    public function __construct(
        private readonly IncomingPaymentService $incomingPaymentService,
        private readonly PayPalImportService $paypalImportService,
        private readonly EmailFetcherService $emailFetcherService,
        private readonly PayPalEmailProcessorService $paypalEmailProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('since', InputArgument::REQUIRED, 'Date since when to fetch emails (YYYY-MM-DD)')
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Payment source to fetch (paypal, all)', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be imported without actually importing')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of emails to process', 100)
            ->addOption('test-connection', null, InputOption::VALUE_NONE, 'Test email server connection and exit')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Show debug information including email content')
            ->addOption('mock', null, InputOption::VALUE_NONE, 'Use mock email data instead of connecting to email server')
            ->setHelp('This command fetches payment emails since a specific date and processes them into incoming payments.

Examples:
  # Fetch all payment emails since 2025-08-27
  php bin/console app:fetch-payment-emails 2025-08-27

  # Fetch only PayPal emails with dry run
  php bin/console app:fetch-payment-emails 2025-08-27 --source=paypal --dry-run

  # Use mock data for testing with debug output
  php bin/console app:fetch-payment-emails 2025-08-27 --source=paypal --dry-run --debug --mock

  # Test email connection
  php bin/console app:fetch-payment-emails 2025-08-27 --test-connection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Test connection if requested
        if ($input->getOption('test-connection')) {
            return $this->testEmailConnection($io);
        }

        $sinceDate = $input->getArgument('since');
        $source = $input->getOption('source');
        $dryRun = $input->getOption('dry-run');
        $debug = $input->getOption('debug');
        $mock = $input->getOption('mock');
        $limit = (int) $input->getOption('limit');

        // Validate date format
        $date = DateTime::createFromFormat('Y-m-d', $sinceDate);
        if (!$date || $date->format('Y-m-d') !== $sinceDate) {
            $io->error('Invalid date format. Please use YYYY-MM-DD format.');
            return Command::FAILURE;
        }

        // Allow any date for email fetching - removed hardcoded test limit
        // $maxTestDate = DateTime::createFromFormat('Y-m-d', '2025-05-23');
        // if ($date > $maxTestDate) {
        //     $io->warning(sprintf('Date %s is after 2025-05-23. Using 2025-05-23 as cutoff date for testing.', $sinceDate));
        //     $date = $maxTestDate;
        // }

        $io->title('Fetching Payment Emails');
        $io->info(sprintf('Fetching emails since: %s', $sinceDate));
        $io->info(sprintf('Source: %s', $source));
        $io->info(sprintf('Limit: %d emails', $limit));
        
        if ($dryRun) {
            $io->warning('DRY RUN MODE - No payments will be actually imported');
        }

        if ($mock) {
            $io->info('MOCK MODE ENABLED - Using mock email data instead of connecting to email server');
        }

        if ($debug) {
            $io->info('DEBUG MODE ENABLED - Additional debug information will be shown');
        }

        $processedCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        try {
            if ($source === 'paypal' || $source === 'all') {
                $io->section('Processing PayPal Emails');
                $result = $this->processPayPalEmails($date, $limit, $dryRun, $debug, $mock, $io);
                $processedCount += $result['processed'];
                $errorCount += $result['errors'];
                $skippedCount += $result['skipped'];
            }
        } catch (\Exception $e) {
            $io->error('Error processing emails: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Email processing completed!');
        $io->table(['Metric', 'Count'], [
            ['Processed', $processedCount],
            ['Skipped', $skippedCount],
            ['Errors', $errorCount],
        ]);

        if ($processedCount > 0 && !$dryRun) {
            $io->note([
                'New payments have been added. You can now run automatic matching and processing:',
                '',
                '# Match payments to users and process them (tickets first, then catering):',
                'php bin/console app:process-payments',
                '',
                '# Or run with dry-run to see what would happen:',
                'php bin/console app:process-payments --dry-run --debug'
            ]);
        }

        return Command::SUCCESS;
    }

    private function testEmailConnection(SymfonyStyle $io): int
    {
        $io->title('Testing Email Connection');
        
        // Check if required environment variables are set
        $requiredVars = ['EMAIL_IMAP_HOST', 'EMAIL_IMAP_USERNAME', 'EMAIL_IMAP_PASSWORD'];
        $missing = [];
        
        foreach ($requiredVars as $var) {
            if (!($_ENV[$var] ?? false)) {
                $missing[] = $var;
            }
        }
        
        if (!empty($missing)) {
            $io->error('Missing required environment variables: ' . implode(', ', $missing));
            $io->note([
                'Please set the following variables in your .env file:',
                '',
                'EMAIL_IMAP_HOST=imap.gmail.com',
                'EMAIL_IMAP_PORT=993',
                'EMAIL_IMAP_USERNAME=your-email@gmail.com',
                'EMAIL_IMAP_PASSWORD=your-app-password',
                'EMAIL_IMAP_ENCRYPTION=ssl',
                'EMAIL_IMAP_FOLDER=INBOX',
                '',
                'For Gmail, you need to:',
                '1. Enable 2FA on your Google account',
                '2. Create an App Password at: https://myaccount.google.com/apppasswords',
                '3. Use the App Password (not your regular password) in EMAIL_IMAP_PASSWORD'
            ]);
            return Command::FAILURE;
        }
        
        try {
            if ($this->emailFetcherService->testConnection()) {
                $io->success('Email connection successful!');
                $io->info([
                    'Configuration:',
                    'Host: ' . ($_ENV['EMAIL_IMAP_HOST'] ?? 'not set'),
                    'Port: ' . ($_ENV['EMAIL_IMAP_PORT'] ?? 'not set'),
                    'Username: ' . ($_ENV['EMAIL_IMAP_USERNAME'] ?? 'not set'),
                    'Encryption: ' . ($_ENV['EMAIL_IMAP_ENCRYPTION'] ?? 'not set'),
                    'Folder: ' . ($_ENV['EMAIL_IMAP_FOLDER'] ?? 'not set'),
                ]);
                return Command::SUCCESS;
            } else {
                $io->error('Email connection failed!');
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error('Email connection test failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function processPayPalEmails(DateTime $since, int $limit, bool $dryRun, bool $debug, bool $mock, SymfonyStyle $io): array
    {
        $processed = 0;
        $errors = 0;
        $skipped = 0;

        try {
            // Fetch ALL emails from PayPal domains since the specified date
            // Use a large limit to fetch all emails since the date, then filter and limit
            if ($mock) {
                // Read from mock file when --mock option is used
                $mockFilePath = __DIR__ . '/../../mockEmails.json';
                if (!file_exists($mockFilePath)) {
                    // Create mock file if it doesn't exist
                    $mockEmails = $this->emailFetcherService->fetchEmailsSince($since, 10000, 'service@paypal.at', false);
                    file_put_contents($mockFilePath, json_encode($mockEmails, JSON_PRETTY_PRINT));
                    $io->info('Created mock email file: ' . $mockFilePath);
                }
                
                $emails = json_decode(file_get_contents($mockFilePath), true);
                $io->info('Using mock email data from: ' . $mockFilePath);
            } else {
                // Fetch real emails from the server for the specified date range
                $emails = $this->emailFetcherService->fetchEmailsSince($since, 10000, 'service@paypal.at', false);
                if ($debug) {
                    $io->info(sprintf('Fetched %d total emails from server since %s', count($emails), $since->format('Y-m-d')));
                }
            }

            // Filter emails by subject - only include emails with "Sie haben eine Zahlung erhalten"
            $paypalPaymentEmails = array_filter($emails, function($email) {
                $subject = $email['subject'] ?? '';
                return $subject === 'Sie haben eine Zahlung erhalten';
            });
            
            // Apply the final limit to matching emails only
            $paypalPaymentEmails = array_slice($paypalPaymentEmails, 0, $limit);
            
            if (empty($paypalPaymentEmails)) {
                $io->info(sprintf('No PayPal payment emails found since %s with subject "Sie haben eine Zahlung erhalten".', $since->format('Y-m-d')));
                return ['processed' => 0, 'errors' => 0, 'skipped' => 0];
            }

            $io->info(sprintf('Found %d PayPal payment emails to process (limited to %d)', count($paypalPaymentEmails), $limit));

            $progressBar = $io->createProgressBar(count($paypalPaymentEmails));
            $progressBar->start();

            foreach ($paypalPaymentEmails as $email) {
                try {
                    // Since we already filtered by subject, skip the PayPal processor validation
                    // and go directly to processing
                    
                    if ($dryRun) {
                        // Extract payment details for dry-run display
                        try {
                            $paymentData = $this->paypalEmailProcessor->extractPaymentDataForDryRun($email);
                            if ($paymentData) {
                                $io->section(sprintf('PayPal email from: %s', $email['date']));
                                $io->text(sprintf('<info>Subject:</info> %s', $email['subject']));
                                $io->text(sprintf('<info>Transaction ID:</info> %s', $paymentData['transaction_id'] ?? '<not found>'));
                                
                                // Display sender info with fallbacks
                                if (isset($paymentData['payer_name'])) {
                                    $io->text(sprintf('<info>Sender:</info> %s', $paymentData['payer_name']));
                                } else {
                                    // Try to extract sender from email data if available
                                    $senderName = '';
                                    if (isset($email['from']) && preg_match('/"?([^<"]+)"?\s+</', $email['from'], $matches)) {
                                        $senderName = trim($matches[1]);
                                    }
                                    $io->text(sprintf('<info>Sender:</info> %s', $senderName ?: '<not found>'));
                                }
                                
                                // Display email with fallbacks
                                if (isset($paymentData['payer_email'])) {
                                    $io->text(sprintf('<info>Email:</info> %s', $paymentData['payer_email']));
                                } elseif (isset($email['from']) && preg_match('/<([^>]+)>/', $email['from'], $matches)) {
                                    $io->text(sprintf('<info>Email:</info> %s', $matches[1]));
                                } else {
                                    $io->text('<info>Email:</info> <not found>');
                                }
                                
                                // Show amount with currency
                                $io->text(sprintf('<info>Amount:</info> %.2f %s', 
                                    $paymentData['amount'] ?? 0, 
                                    $paymentData['currency'] ?? 'EUR'
                                ));
                                
                                // Show reference/message with proper context
                                if (isset($paymentData['reference'])) {
                                    $io->text(sprintf('<info>Message/Reference:</info> %s', $paymentData['reference']));
                                } elseif (isset($paymentData['description'])) {
                                    $io->text(sprintf('<info>Description:</info> %s', $paymentData['description']));
                                } else {
                                    $io->text('<info>Message/Reference:</info> <none>');
                                }
                                
                                // Debug data if requested
                                if ($debug) {
                                    $io->text('<info>Debug - Raw data:</info>');
                                    $io->text(json_encode($paymentData, JSON_PRETTY_PRINT));
                                    
                                    $io->text('<info>Debug - Email content:</info>');
                                    $plainTextContent = $this->cleanEmailContent($email['content']);
                                    $io->text('  ' . str_replace("\n", "\n  ", substr($plainTextContent, 0, 500)) . '...');
                                }
                                
                                $io->newLine();
                                $processed++;
                            } else {
                                $io->warning(sprintf('Would skip PayPal email: %s (could not extract payment data)', $email['subject']));
                                if ($debug) {
                                    $io->text('<info>Debug - Email content:</info>');
                                    $plainTextContent = $this->cleanEmailContent($email['content']);
                                    $io->text('  ' . str_replace("\n", "\n  ", substr($plainTextContent, 0, 500)) . '...');
                                }
                                $skipped++;
                            }
                        } catch (\Exception $e) {
                            $io->error(sprintf('Error processing PayPal email: %s (%s)', $email['subject'], $e->getMessage()));
                            if ($debug) {
                                $io->text('<info>Debug - Email content:</info>');
                                $plainTextContent = $this->cleanEmailContent($email['content']);
                                $io->text('  ' . str_replace("\n", "\n  ", substr($plainTextContent, 0, 500)) . '...');
                            }
                            $skipped++;
                        }
                    } else {
                        $payment = $this->paypalEmailProcessor->processPayPalEmail($email);
                        if ($payment) {
                            $payerName = $payment->getPayerName() ?: 'unknown';
                            
                            $io->text(sprintf('Created payment: %s EUR from %s', 
                                $payment->getAmount(), 
                                $payerName
                            ));
                            $processed++;
                        } else {
                            // Check if this was skipped due to duplicate
                            $paymentData = $this->paypalEmailProcessor->extractPaymentDataForDryRun($email);
                            if ($paymentData && isset($paymentData['transaction_id']) && !empty($paymentData['transaction_id'])) {
                                $io->text(sprintf('<comment>Skipped duplicate: %s EUR from %s (Transaction ID: %s)</comment>', 
                                    $paymentData['amount'] ?? 'unknown', 
                                    $paymentData['payer_name'] ?? 'unknown',
                                    $paymentData['transaction_id']
                                ));
                            } else {
                                $io->text(sprintf('<comment>Skipped: %s (could not parse or missing transaction ID)</comment>', $email['subject']));
                            }
                            $skipped++;
                        }
                    }

                } catch (\Exception $e) {
                    $io->error(sprintf('Error processing email "%s": %s', $email['subject'] ?? 'unknown', $e->getMessage()));
                    $errors++;
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);

        } catch (\Exception $e) {
            $io->error('Error fetching PayPal emails: ' . $e->getMessage());
            $errors++;
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'skipped' => $skipped,
        ];
    }


    /**
     * Clean HTML content by removing CSS, scripts, and HTML tags
     */
    private function cleanEmailContent(string $content): string
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
}
