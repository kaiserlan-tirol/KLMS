<?php

namespace App\Service;

use App\Entity\UserTransaction;
use App\Entity\UserBalance;
use App\Repository\UserTransactionRepository;
use App\Repository\UserBalanceRepository;
use App\Entity\CateringOrder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use DateTimeImmutable;
use RuntimeException;

class TransactionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserTransactionRepository $transactionRepository,
        private UserBalanceRepository $balanceRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Record an incoming payment
     */
    public function recordIncomingPayment(
        UuidInterface $user,
        int $amount,
        string $source,
        string $bankReference = null,
        string $description = null
    ): UserTransaction {
        // Check for duplicates
        $duplicate = $this->transactionRepository->findDuplicatePayment($user, $amount);
        if ($duplicate) {
            throw new RuntimeException(
                "Duplicate payment detected. Similar payment of {$amount} cents found for user {$user}"
            );
        }

        $transaction = new UserTransaction();
        $transaction->setUser($user)
            ->setType(UserTransaction::TYPE_INCOMING_PAYMENT)
            ->setCategory(UserTransaction::CATEGORY_CATERING)
            ->setAmount($amount)
            ->setSource($source)
            ->setBankReference($bankReference)
            ->setDescription($description ?? "Incoming payment via {$source}")
            ->setStatus(UserTransaction::STATUS_COMPLETED);

        $this->entityManager->beginTransaction();
        try {
            $this->transactionRepository->save($transaction);
            $this->updateUserBalance($user);
            $this->entityManager->commit();

            $this->logger->info('Incoming payment recorded', [
                'user' => $user->toString(),
                'amount' => $amount,
                'source' => $source,
                'transaction_id' => $transaction->getId()
            ]);

            return $transaction;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to record incoming payment', [
                'user' => $user->toString(),
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process a catering order payment
     */
    public function processOrderPayment(CateringOrder $order): UserTransaction
    {
        $amount = -$order->getTotalPriceCents(); // Negative for deduction
        
        $transaction = new UserTransaction();
        $transaction->setUser($order->getUser())
            ->setType(UserTransaction::TYPE_ORDER_PAYMENT)
            ->setCategory(UserTransaction::CATEGORY_CATERING)
            ->setAmount($amount)
            ->setSource('catering_order')
            ->setReferenceType('catering_order')
            ->setReferenceId((string) $order->getId())
            ->setDescription("Payment for catering order #{$order->getId()}")
            ->setStatus(UserTransaction::STATUS_COMPLETED);

        $this->entityManager->beginTransaction();
        try {
            $this->transactionRepository->save($transaction);
            $this->updateUserBalance($order->getUser());
            $this->entityManager->commit();

            $this->logger->info('Order payment processed', [
                'user' => $order->getUser()->toString(),
                'order_id' => $order->getId(),
                'amount' => $amount,
                'transaction_id' => $transaction->getId()
            ]);

            return $transaction;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process order payment', [
                'user' => $order->getUser()->toString(),
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process a catering order refund
     */
    public function processOrderRefund(CateringOrder $order): UserTransaction
    {
        $amount = $order->getTotalPriceCents(); // Positive for credit restoration
        
        $transaction = new UserTransaction();
        $transaction->setUser($order->getUser())
            ->setType(UserTransaction::TYPE_ORDER_REFUND)
            ->setCategory(UserTransaction::CATEGORY_CATERING)
            ->setAmount($amount)
            ->setSource('catering_order')
            ->setReferenceType('catering_order')
            ->setReferenceId((string) $order->getId())
            ->setDescription("Refund for catering order #{$order->getId()}")
            ->setStatus(UserTransaction::STATUS_COMPLETED);

        $this->entityManager->beginTransaction();
        try {
            $this->transactionRepository->save($transaction);
            $this->updateUserBalance($order->getUser());
            $this->entityManager->commit();

            $this->logger->info('Order refund processed', [
                'user' => $order->getUser()->toString(),
                'order_id' => $order->getId(),
                'amount' => $amount,
                'transaction_id' => $transaction->getId()
            ]);

            return $transaction;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process order refund', [
                'user' => $order->getUser()->toString(),
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Process a shop order payment (for manual confirmations)
     */
    public function processShopOrderPayment(
        UuidInterface $user,
        int $amount,
        int $orderId,
        string $description = null
    ): UserTransaction {
        $this->entityManager->beginTransaction();
        
        try {
            $transaction = new UserTransaction();
            $transaction->setUser($user)
                ->setType(UserTransaction::TYPE_ORDER_PAYMENT)
                ->setCategory(UserTransaction::CATEGORY_SHOP)
                ->setAmount(-$amount) // Negative for payment
                ->setReferenceType('shop_order')
                ->setReferenceId((string)$orderId)
                ->setDescription($description ?? "Shop order #{$orderId} payment");
            
            $this->transactionRepository->save($transaction);
            $this->updateUserBalance($user);
            $this->entityManager->commit();

            $this->logger->info('Shop order payment processed', [
                'user' => $user->toString(),
                'order_id' => $orderId,
                'amount' => $amount
            ]);

            return $transaction;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process shop order payment', [
                'user' => $user->toString(),
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Add manual credit to a user account
     */
    public function addManualCredit(
        UuidInterface $user,
        int $amount,
        string $category = UserTransaction::CATEGORY_CATERING,
        string $reason = null
    ): UserTransaction {
        $type = $amount > 0 ? UserTransaction::TYPE_MANUAL_CREDIT_ADDITION : UserTransaction::TYPE_MANUAL_CREDIT_DEDUCTION;
        $absoluteAmount = abs($amount);
        
        $transaction = new UserTransaction();
        $transaction->setUser($user)
            ->setType($type)
            ->setCategory($category)
            ->setAmount($amount) // Keep original sign
            ->setSource('manual')
            ->setDescription($reason ?? ($amount > 0 ? "Manual credit addition" : "Manual credit deduction"))
            ->setStatus(UserTransaction::STATUS_COMPLETED);

        $this->entityManager->beginTransaction();
        try {
            $this->transactionRepository->save($transaction);
            $this->updateUserBalance($user);
            $this->entityManager->commit();

            $this->logger->info('Manual credit processed', [
                'user' => $user->toString(),
                'amount' => $amount,
                'category' => $category,
                'type' => $type,
                'transaction_id' => $transaction->getId()
            ]);

            return $transaction;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process manual credit', [
                'user' => $user->toString(),
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get current balance for a user
     */
    public function getUserBalance(UuidInterface $user): UserBalance
    {
        return $this->balanceRepository->findOrCreateForUser($user);
    }

    /**
     * Check if user has sufficient balance for a purchase
     */
    public function hasInsufficientBalance(UuidInterface $user, int $amount, string $category = UserTransaction::CATEGORY_CATERING): bool
    {
        $balance = $this->getUserBalance($user);
        
        $availableBalance = match ($category) {
            UserTransaction::CATEGORY_CATERING => $balance->getCateringBalance(),
            UserTransaction::CATEGORY_SHOP => $balance->getShopBalance(),
            default => $balance->getTotalBalance()
        };

        return $availableBalance < $amount;
    }

    /**
     * Get transaction history for a user
     */
    public function getUserTransactionHistory(UuidInterface $user, int $limit = 20): array
    {
        return $this->transactionRepository->findRecentTransactions($user, $limit);
    }

    /**
     * Find transactions by order reference
     */
    public function getOrderTransactions(int $orderId): array
    {
        return $this->transactionRepository->findByReference('catering_order', (string) $orderId);
    }

    /**
     * Check for duplicate payments to prevent double processing
     */
    public function isDuplicatePayment(UuidInterface $user, int $amount, int $hours = 24): bool
    {
        return $this->transactionRepository->findDuplicatePayment($user, $amount, $hours) !== null;
    }

    /**
     * Update user balance based on all transactions
     */
    private function updateUserBalance(UuidInterface $user): void
    {
        $balance = $this->balanceRepository->findOrCreateForUser($user);
        
        $cateringBalance = $this->transactionRepository->calculateCateringBalance($user);
        $shopBalance = $this->transactionRepository->calculateShopBalance($user);
        
        $balance->setCateringBalance($cateringBalance)
            ->setShopBalance($shopBalance);
        
        $this->balanceRepository->save($balance);
    }

    /**
     * Get platform-wide transaction statistics
     */
    public function getPlatformStats(): array
    {
        return $this->balanceRepository->getPlatformBalanceStats();
    }

    /**
     * Process pending incoming payments (for automation)
     */
    public function processPendingPayments(): int
    {
        $pendingPayments = $this->transactionRepository->findPendingIncomingPayments();
        $processed = 0;

        foreach ($pendingPayments as $payment) {
            try {
                $payment->setStatus(UserTransaction::STATUS_COMPLETED);
                $this->transactionRepository->save($payment);
                $this->updateUserBalance($payment->getUser());
                $processed++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to process pending payment', [
                    'transaction_id' => $payment->getId(),
                    'error' => $e->getMessage()
                ]);
                $payment->setStatus(UserTransaction::STATUS_FAILED);
                $this->transactionRepository->save($payment);
            }
        }

        return $processed;
    }
}
