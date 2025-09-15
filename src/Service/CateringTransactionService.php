<?php

namespace App\Service;

use App\Entity\CateringBalance;
use App\Entity\CateringTransaction;
use App\Entity\User;
use App\Repository\CateringBalanceRepository;
use App\Repository\CateringTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Unified service for handling all catering transactions and balance management
 * Replaces the functionality of multiple overlapping services
 */
class CateringTransactionService
{
    public function __construct(
        private CateringTransactionRepository $transactionRepository,
        private CateringBalanceRepository $balanceRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Process an incoming payment (from email, API, etc.)
     */
    public function processIncomingPayment(
        UuidInterface $userId,
        int $amount,
        string $source,
        ?string $externalId = null,
        ?string $description = null,
        array $metadata = []
    ): CateringTransaction {
        // Check for duplicates based on external ID
        if ($externalId && $this->hasDuplicateByExternalId($externalId)) {
            $this->logger->warning('Duplicate payment detected by external ID', [
                'external_id' => $externalId,
                'user_id' => $userId,
                'amount' => $amount
            ]);
            
            return $this->createDuplicateTransaction($userId, $amount, $source, $externalId, $description, $metadata);
        }

        // Check for potential duplicates by amount and timeframe
        if ($this->isPotentialDuplicate($userId, $amount, $source)) {
            $this->logger->warning('Potential duplicate payment detected', [
                'user_id' => $userId,
                'amount' => $amount,
                'source' => $source
            ]);
            
            return $this->createDuplicateTransaction($userId, $amount, $source, $externalId, $description, $metadata);
        }

        // Create new payment transaction
        $transaction = new CateringTransaction();
        $transaction->setUser($userId);
        $transaction->setType(CateringTransaction::TYPE_PAYMENT_RECEIVED);
        $transaction->setAmount($amount);
        $transaction->setSource($source);
        $transaction->setStatus(CateringTransaction::STATUS_PENDING);
        $transaction->setExternalId($externalId);
        $transaction->setDescription($description);
        $transaction->setMetadata($metadata);

        $this->transactionRepository->save($transaction, true);

        $this->logger->info('Incoming payment processed', [
            'transaction_id' => $transaction->getId(),
            'user_id' => $userId,
            'amount' => $amount,
            'source' => $source
        ]);

        return $transaction;
    }

    /**
     * Match a pending transaction to a user and mark as matched
     */
    public function matchTransactionToUser(CateringTransaction $transaction, UuidInterface $userId): CateringTransaction
    {
        if ($transaction->getStatus() !== CateringTransaction::STATUS_PENDING) {
            throw new \InvalidArgumentException('Transaction is not in pending status');
        }

        $transaction->setUser($userId);
        $transaction->setStatus(CateringTransaction::STATUS_MATCHED);
        $transaction->setProcessedAt(new \DateTime());

        $this->transactionRepository->save($transaction, true);

        $this->logger->info('Transaction matched to user', [
            'transaction_id' => $transaction->getId(),
            'user_id' => $userId
        ]);

        return $transaction;
    }

    /**
     * Process a matched transaction and update user balance
     */
    public function processMatchedTransaction(CateringTransaction $transaction): CateringTransaction
    {
        if ($transaction->getStatus() !== CateringTransaction::STATUS_MATCHED) {
            throw new \InvalidArgumentException('Transaction is not in matched status');
        }

        if ($transaction->getIsDuplicate()) {
            throw new \InvalidArgumentException('Cannot process duplicate transaction');
        }

        // Update balance
        $this->updateUserBalance($transaction->getUser(), $transaction->getAmount());

        // Mark as processed
        $transaction->setStatus(CateringTransaction::STATUS_PROCESSED);
        $transaction->setProcessedAt(new \DateTime());

        $this->transactionRepository->save($transaction, true);

        $this->logger->info('Transaction processed and balance updated', [
            'transaction_id' => $transaction->getId(),
            'user_id' => $transaction->getUser(),
            'amount' => $transaction->getAmount()
        ]);

        return $transaction;
    }

    /**
     * Record an order payment (deduct from balance)
     */
    public function recordOrderPayment(UuidInterface $userId, int $amount, ?string $orderId = null): CateringTransaction
    {
        // Check if user has sufficient balance
        $balance = $this->getUserBalance($userId);
        if ($balance < $amount) {
            throw new \InvalidArgumentException('Insufficient balance for order payment');
        }

        $transaction = new CateringTransaction();
        $transaction->setUser($userId);
        $transaction->setType(CateringTransaction::TYPE_ORDER_PAYMENT);
        $transaction->setAmount(-$amount); // Negative for deduction
        $transaction->setSource(CateringTransaction::SOURCE_ORDER);
        $transaction->setStatus(CateringTransaction::STATUS_PROCESSED);
        $transaction->setProcessedAt(new \DateTime());
        $transaction->setRelatedOrderId($orderId);

        $this->transactionRepository->save($transaction);

        // Update balance
        $this->updateUserBalance($userId, -$amount);

        $this->entityManager->flush();

        $this->logger->info('Order payment recorded', [
            'transaction_id' => $transaction->getId(),
            'user_id' => $userId,
            'amount' => $amount,
            'order_id' => $orderId
        ]);

        return $transaction;
    }

    /**
     * Record an order refund (add to balance)
     */
    public function recordOrderRefund(UuidInterface $userId, int $amount, ?string $orderId = null): CateringTransaction
    {
        $transaction = new CateringTransaction();
        $transaction->setUser($userId);
        $transaction->setType(CateringTransaction::TYPE_ORDER_REFUND);
        $transaction->setAmount($amount); // Positive for addition
        $transaction->setSource(CateringTransaction::SOURCE_ORDER);
        $transaction->setStatus(CateringTransaction::STATUS_PROCESSED);
        $transaction->setProcessedAt(new \DateTime());
        $transaction->setRelatedOrderId($orderId);

        $this->transactionRepository->save($transaction);

        // Update balance
        $this->updateUserBalance($userId, $amount);

        $this->entityManager->flush();

        $this->logger->info('Order refund recorded', [
            'transaction_id' => $transaction->getId(),
            'user_id' => $userId,
            'amount' => $amount,
            'order_id' => $orderId
        ]);

        return $transaction;
    }

    /**
     * Make a manual credit adjustment (admin function)
     */
    public function adjustCredit(
        UuidInterface $userId,
        int $amount,
        string $reason,
        ?string $adminUserId = null
    ): CateringTransaction {
        $transaction = new CateringTransaction();
        $transaction->setUser($userId);
        $transaction->setType(CateringTransaction::TYPE_CREDIT_ADJUSTMENT);
        $transaction->setAmount($amount);
        $transaction->setSource(CateringTransaction::SOURCE_ADMIN);
        $transaction->setStatus(CateringTransaction::STATUS_PROCESSED);
        $transaction->setProcessedAt(new \DateTime());
        $transaction->setDescription($reason);
        
        if ($adminUserId) {
            $transaction->setMetadata(['admin_user_id' => $adminUserId]);
        }

        $this->transactionRepository->save($transaction);

        // Update balance
        $this->updateUserBalance($userId, $amount);

        $this->entityManager->flush();

        $this->logger->info('Credit adjustment made', [
            'transaction_id' => $transaction->getId(),
            'user_id' => $userId,
            'amount' => $amount,
            'reason' => $reason,
            'admin_user_id' => $adminUserId
        ]);

        return $transaction;
    }

    /**
     * Get current balance for a user
     */
    public function getUserBalance(UuidInterface $userId): int
    {
        $balance = $this->balanceRepository->findByUser($userId);
        return $balance ? $balance->getBalance() : 0;
    }

    /**
     * Get all transactions for a user
     */
    public function getUserTransactions(UuidInterface $userId): array
    {
        return $this->transactionRepository->findByUser($userId);
    }

    /**
     * Check if payment is a potential duplicate
     */
    public function isPotentialDuplicate(UuidInterface $userId, int $amount, string $source): bool
    {
        $since = new \DateTime('-7 days'); // Check last 7 days
        
        $recentPayments = $this->transactionRepository->findRecentProcessedPayments($userId, $amount, $since);
        
        return count($recentPayments) > 0;
    }

    /**
     * Check for duplicates by external ID
     */
    private function hasDuplicateByExternalId(string $externalId): bool
    {
        $existing = $this->transactionRepository->findByExternalId($externalId);
        return count($existing) > 0;
    }

    /**
     * Create a duplicate transaction (marked as duplicate, not processed)
     */
    private function createDuplicateTransaction(
        UuidInterface $userId,
        int $amount,
        string $source,
        ?string $externalId = null,
        ?string $description = null,
        array $metadata = []
    ): CateringTransaction {
        $transaction = new CateringTransaction();
        $transaction->setUser($userId);
        $transaction->setType(CateringTransaction::TYPE_PAYMENT_RECEIVED);
        $transaction->setAmount($amount);
        $transaction->setSource($source);
        $transaction->setStatus(CateringTransaction::STATUS_DUPLICATE);
        $transaction->setIsDuplicate(true);
        $transaction->setExternalId($externalId);
        $transaction->setDescription($description);
        $transaction->setMetadata($metadata);

        $this->transactionRepository->save($transaction, true);

        return $transaction;
    }

    /**
     * Update user balance
     */
    private function updateUserBalance(UuidInterface $userId, int $amount): void
    {
        $balance = $this->balanceRepository->getOrCreateForUser($userId);
        $balance->addToBalance($amount);
        $this->balanceRepository->save($balance);
    }

    /**
     * Recalculate balance from all transactions (for verification/cleanup)
     */
    public function recalculateUserBalance(UuidInterface $userId): int
    {
        $calculatedBalance = $this->transactionRepository->calculateUserBalance($userId);
        
        $balance = $this->balanceRepository->getOrCreateForUser($userId);
        $oldBalance = $balance->getBalance();
        
        $balance->setBalance($calculatedBalance);
        $this->balanceRepository->save($balance, true);

        if ($oldBalance !== $calculatedBalance) {
            $this->logger->warning('Balance recalculated with difference', [
                'user_id' => $userId,
                'old_balance' => $oldBalance,
                'new_balance' => $calculatedBalance,
                'difference' => $calculatedBalance - $oldBalance
            ]);
        }

        return $calculatedBalance;
    }
}
