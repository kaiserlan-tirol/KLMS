<?php

namespace App\Command;

use App\Service\TransactionService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-duplicate-detection',
    description: 'Test duplicate payment detection between manual shop order confirmation and PayPal automation'
)]
class TestDuplicateDetectionCommand extends Command
{
    public function __construct(
        private TransactionService $transactionService,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing Shop Order Duplicate Payment Detection');

        // Test scenario: Create a test user and simulate the duplicate payment scenario
        $testUserId = Uuid::uuid4();
        $testAmount = 5000; // 50 EUR in cents
        
        $io->text("Test User: {$testUserId}");
        $io->text("Test Amount: {$testAmount} cents (50.00 EUR)");
        
        // Test 1: Check initial state (no duplicates)
        $io->section('Test 1: Initial duplicate check');
        $hasDuplicate = $this->transactionService->isDuplicatePayment($testUserId, $testAmount);
        $io->text("Has duplicate payment: " . ($hasDuplicate ? 'YES' : 'NO'));
        
        // Test 2: Simulate manual "Zahlung bestätigen" by creating a shop order payment
        $io->section('Test 2: Simulate manual payment confirmation');
        try {
            $transaction = $this->transactionService->processShopOrderPayment(
                $testUserId,
                $testAmount,
                999, // test order ID
                'Test manual payment confirmation'
            );
            $io->success("Created shop order payment transaction: {$transaction->getId()}");
            $io->text("Transaction type: {$transaction->getType()}");
            $io->text("Transaction amount: {$transaction->getAmount()} cents");
        } catch (\Exception $e) {
            $io->error("Failed to create shop order payment: {$e->getMessage()}");
            return Command::FAILURE;
        }
        
        // Test 3: Check for duplicates after manual confirmation
        $io->section('Test 3: Duplicate check after manual confirmation');
        $hasDuplicate = $this->transactionService->isDuplicatePayment($testUserId, $testAmount);
        $io->text("Has duplicate payment: " . ($hasDuplicate ? 'YES' : 'NO'));
        
        if ($hasDuplicate) {
            $io->success('✅ Duplicate detection is working! PayPal automation would skip this payment.');
        } else {
            $io->error('❌ Duplicate detection is NOT working! PayPal automation would process this payment again.');
        }
        
        // Test 4: Test with opposite amount (incoming payment perspective)
        $io->section('Test 4: Check duplicate with positive amount (incoming payment)');
        $hasDuplicate = $this->transactionService->isDuplicatePayment($testUserId, $testAmount);
        $io->text("Has duplicate payment for +{$testAmount}: " . ($hasDuplicate ? 'YES' : 'NO'));
        
        // Cleanup
        $io->section('Cleanup');
        $this->entityManager->remove($transaction);
        $this->entityManager->flush();
        $io->text('Test transaction removed');
        
        return Command::SUCCESS;
    }
}
