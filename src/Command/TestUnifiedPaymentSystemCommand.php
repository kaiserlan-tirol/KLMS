<?php

namespace App\Command;

use App\Service\TransactionService;
use App\Service\CateringService;
use App\Entity\User;
use App\Idm\IdmManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

#[AsCommand(
    name: 'app:test-unified-payment-system',
    description: 'Test the unified payment system functionality'
)]
class TestUnifiedPaymentSystemCommand extends Command
{
    public function __construct(
        private TransactionService $transactionService,
        private CateringService $cateringService,
        private IdmManager $idmManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user-uuid', InputArgument::REQUIRED, 'UUID of the user to test with')
            ->addOption('add-credit', 'c', InputOption::VALUE_OPTIONAL, 'Add this amount of credit (in euros)', null)
            ->addOption('check-balance', 'b', InputOption::VALUE_NONE, 'Check user balance')
            ->addOption('transaction-history', 't', InputOption::VALUE_NONE, 'Show transaction history')
            ->addOption('duplicate-test', 'd', InputOption::VALUE_OPTIONAL, 'Test duplicate detection with amount (in euros)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $userUuid = $input->getArgument('user-uuid');
        
        try {
            $uuid = Uuid::fromString($userUuid);
        } catch (\Exception $e) {
            $io->error('Invalid UUID format: ' . $userUuid);
            return Command::FAILURE;
        }

        // Verify user exists
        $userRepo = $this->idmManager->getRepository(User::class);
        $user = $userRepo->findOneById($uuid);
        if (!$user) {
            $io->error('User not found: ' . $userUuid);
            return Command::FAILURE;
        }

        $io->title('Unified Payment System Test');
        $io->writeln("Testing with user: {$user->getDisplayName()} ({$uuid->toString()})");

        // Check balance
        if ($input->getOption('check-balance') || !$input->getOption('add-credit') && !$input->getOption('transaction-history') && !$input->getOption('duplicate-test')) {
            $this->checkBalance($io, $uuid);
        }

        // Add credit
        if ($addCredit = $input->getOption('add-credit')) {
            $this->addCredit($io, $uuid, (float) $addCredit);
        }

        // Show transaction history
        if ($input->getOption('transaction-history')) {
            $this->showTransactionHistory($io, $uuid);
        }

        // Test duplicate detection
        if ($duplicateAmount = $input->getOption('duplicate-test')) {
            $this->testDuplicateDetection($io, $uuid, (float) $duplicateAmount);
        }

        return Command::SUCCESS;
    }

    private function checkBalance(SymfonyStyle $io, $uuid): void
    {
        $io->section('Current Balance');
        
        // New system balance
        $balance = $this->transactionService->getUserBalance($uuid);
        
        $table = new Table($io);
        $table->setHeaders(['Type', 'Amount (€)', 'Amount (cents)']);
        $table->addRows([
            ['Catering Balance', number_format($balance->getCateringBalanceInEuros(), 2), $balance->getCateringBalance()],
            ['Shop Balance', number_format($balance->getShopBalanceInEuros(), 2), $balance->getShopBalance()],
            ['Total Balance', number_format($balance->getTotalBalanceInEuros(), 2), $balance->getTotalBalance()],
        ]);
        $table->render();

        // Compare with legacy system
        $legacyBalance = $this->cateringService->getUserCredit($uuid);
        $io->writeln("Legacy catering balance: " . number_format($legacyBalance / 100, 2) . " € ({$legacyBalance} cents)");
        
        if ($balance->getCateringBalance() !== $legacyBalance) {
            $io->warning('Balance mismatch between new and legacy systems!');
        } else {
            $io->success('Balance matches between new and legacy systems');
        }
    }

    private function addCredit(SymfonyStyle $io, $uuid, float $euros): void
    {
        $io->section('Adding Credit');
        $cents = (int) ($euros * 100);
        
        try {
            $transaction = $this->transactionService->addManualCredit(
                $uuid,
                $cents,
                'catering',
                'Test credit addition via command'
            );
            
            $io->success("Added {$euros} € ({$cents} cents) to user account");
            $io->writeln("Transaction ID: {$transaction->getId()}");
            
            // Show updated balance
            $this->checkBalance($io, $uuid);
            
        } catch (\Exception $e) {
            $io->error('Failed to add credit: ' . $e->getMessage());
        }
    }

    private function showTransactionHistory(SymfonyStyle $io, $uuid): void
    {
        $io->section('Transaction History (Last 10)');
        
        $transactions = $this->transactionService->getUserTransactionHistory($uuid, 10);
        
        if (empty($transactions)) {
            $io->info('No transactions found');
            return;
        }

        $table = new Table($io);
        $table->setHeaders(['Date', 'Type', 'Amount (€)', 'Description', 'Status']);
        
        foreach ($transactions as $transaction) {
            $table->addRow([
                $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
                $transaction->getType(),
                number_format($transaction->getAmountInEuros(), 2),
                $transaction->getDescription(),
                $transaction->getStatus()
            ]);
        }
        
        $table->render();
    }

    private function testDuplicateDetection(SymfonyStyle $io, $uuid, float $euros): void
    {
        $io->section('Testing Duplicate Detection');
        $cents = (int) ($euros * 100);
        
        $isDuplicate = $this->transactionService->isDuplicatePayment($uuid, $cents);
        
        if ($isDuplicate) {
            $io->warning("Duplicate payment detected for amount {$euros} €");
        } else {
            $io->success("No duplicate detected for amount {$euros} €");
        }
        
        // Show recent similar transactions
        $io->writeln('Recent transactions with similar amounts:');
        $transactions = $this->transactionService->getUserTransactionHistory($uuid, 20);
        
        $similarFound = false;
        foreach ($transactions as $transaction) {
            if (abs($transaction->getAmount() - $cents) < 50) { // Within 50 cents
                $io->writeln(sprintf(
                    '- %s: %s € (%s) - %s',
                    $transaction->getCreatedAt()->format('Y-m-d H:i'),
                    number_format($transaction->getAmountInEuros(), 2),
                    $transaction->getType(),
                    $transaction->getDescription()
                ));
                $similarFound = true;
            }
        }
        
        if (!$similarFound) {
            $io->writeln('No similar transactions found');
        }
    }
}
