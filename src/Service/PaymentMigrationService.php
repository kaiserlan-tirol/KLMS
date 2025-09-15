<?php

namespace App\Service;

use App\Entity\UserTransaction;
use App\Entity\UserBalance;
use App\Repository\UserTransactionRepository;
use App\Repository\UserBalanceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class PaymentMigrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserTransactionRepository $transactionRepository,
        private UserBalanceRepository $balanceRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Migrate data from old payment tables to new unified system
     * WARNING: This will truncate existing UserTransaction and UserBalance tables
     */
    public function migrateFromLegacyTables(): array
    {
        $this->logger->info('Starting payment system migration');
        
        $stats = [
            'incoming_payments' => 0,
            'credit_transactions' => 0,
            'user_balances' => 0,
            'errors' => []
        ];

        $this->entityManager->beginTransaction();
        try {
            // Clear existing data
            $this->clearUnifiedTables();

            // Migrate incoming payments
            $stats['incoming_payments'] = $this->migrateIncomingPayments();
            
            // Migrate catering credit transactions
            $stats['credit_transactions'] = $this->migrateCateringCreditTransactions();
            
            // Rebuild user balances
            $stats['user_balances'] = $this->rebuildUserBalances();

            $this->entityManager->commit();
            $this->logger->info('Payment system migration completed', $stats);
            
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Payment system migration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        return $stats;
    }

    /**
     * Clear existing unified tables
     */
    private function clearUnifiedTables(): void
    {
        $connection = $this->entityManager->getConnection();
        
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE user_transaction');
        $connection->executeStatement('TRUNCATE TABLE user_balance');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->logger->info('Cleared unified transaction tables');
    }

    /**
     * Migrate data from incoming_payment table
     */
    private function migrateIncomingPayments(): int
    {
        $connection = $this->entityManager->getConnection();
        $stmt = $connection->executeQuery('SELECT * FROM incoming_payment ORDER BY created_at ASC');
        $count = 0;

        while ($row = $stmt->fetchAssociative()) {
            try {
                $transaction = new UserTransaction();
                $transaction->setUser(Uuid::fromString($row['user']))
                    ->setType(UserTransaction::TYPE_INCOMING_PAYMENT)
                    ->setCategory(UserTransaction::CATEGORY_CATERING)
                    ->setAmount((int) $row['amount_cents'])
                    ->setSource($row['source'] ?? 'legacy_import')
                    ->setBankReference($row['bank_reference'])
                    ->setDescription($row['description'] ?? 'Migrated from incoming_payment')
                    ->setStatus($this->mapLegacyStatus($row['status'] ?? 'completed'))
                    ->setCreatedAt(new \DateTimeImmutable($row['created_at']));

                $this->entityManager->persist($transaction);
                $count++;

                if ($count % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to migrate incoming payment', [
                    'payment_id' => $row['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        
        return $count;
    }

    /**
     * Migrate data from catering_credit_transaction table
     */
    private function migrateCateringCreditTransactions(): int
    {
        $connection = $this->entityManager->getConnection();
        $stmt = $connection->executeQuery('SELECT * FROM catering_credit_transaction ORDER BY created_at ASC');
        $count = 0;

        while ($row = $stmt->fetchAssociative()) {
            try {
                $transaction = new UserTransaction();
                $transaction->setUser(Uuid::fromString($row['user']))
                    ->setType($this->mapLegacyTransactionType($row['type']))
                    ->setCategory(UserTransaction::CATEGORY_CATERING)
                    ->setAmount((int) $row['amount_cents'])
                    ->setSource('catering_order')
                    ->setDescription($row['description'] ?? 'Migrated from catering_credit_transaction')
                    ->setStatus(UserTransaction::STATUS_COMPLETED)
                    ->setCreatedAt(new \DateTimeImmutable($row['created_at']));

                // Set order reference if available
                if (!empty($row['catering_order_id'])) {
                    $transaction->setReferenceType('catering_order')
                        ->setReferenceId((string) $row['catering_order_id']);
                }

                $this->entityManager->persist($transaction);
                $count++;

                if ($count % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to migrate credit transaction', [
                    'transaction_id' => $row['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        
        return $count;
    }

    /**
     * Rebuild user balances from transactions
     */
    private function rebuildUserBalances(): int
    {
        $connection = $this->entityManager->getConnection();
        
        // Get all unique users from transactions
        $stmt = $connection->executeQuery(
            'SELECT DISTINCT user FROM user_transaction'
        );
        
        $count = 0;
        while ($row = $stmt->fetchAssociative()) {
            try {
                $user = Uuid::fromString($row['user']);
                
                $cateringBalance = $this->transactionRepository->calculateCateringBalance($user);
                $shopBalance = $this->transactionRepository->calculateShopBalance($user);
                
                $balance = new UserBalance();
                $balance->setUser($user)
                    ->setCateringBalance($cateringBalance)
                    ->setShopBalance($shopBalance);
                
                $this->entityManager->persist($balance);
                $count++;

                if ($count % 50 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to rebuild user balance', [
                    'user' => $row['user'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        
        return $count;
    }

    /**
     * Map legacy transaction types to new unified types
     */
    private function mapLegacyTransactionType(string $legacyType): string
    {
        return match ($legacyType) {
            'order_payment', 'TYPE_ORDER_PAYMENT' => UserTransaction::TYPE_ORDER_PAYMENT,
            'order_refund', 'TYPE_ORDER_REFUND' => UserTransaction::TYPE_ORDER_REFUND,
            'credit_addition', 'TYPE_CREDIT_ADDITION' => UserTransaction::TYPE_MANUAL_CREDIT_ADDITION,
            'incoming_payment', 'TYPE_INCOMING_PAYMENT' => UserTransaction::TYPE_INCOMING_PAYMENT,
            default => UserTransaction::TYPE_MANUAL_CREDIT_ADDITION
        };
    }

    /**
     * Map legacy status to new unified status
     */
    private function mapLegacyStatus(string $legacyStatus): string
    {
        return match (strtolower($legacyStatus)) {
            'pending' => UserTransaction::STATUS_PENDING,
            'completed', 'success' => UserTransaction::STATUS_COMPLETED,
            'failed', 'error' => UserTransaction::STATUS_FAILED,
            'cancelled' => UserTransaction::STATUS_CANCELLED,
            default => UserTransaction::STATUS_COMPLETED
        };
    }

    /**
     * Generate a comparison report between old and new systems
     */
    public function generateMigrationReport(): array
    {
        $connection = $this->entityManager->getConnection();
        
        $report = [];
        
        // Count records in old tables
        $report['legacy_counts'] = [
            'incoming_payment' => $this->getTableCount('incoming_payment'),
            'catering_credit_transaction' => $this->getTableCount('catering_credit_transaction'),
            'user_catering_credit' => $this->getTableCount('user_catering_credit')
        ];
        
        // Count records in new tables
        $report['unified_counts'] = [
            'user_transaction' => $this->getTableCount('user_transaction'),
            'user_balance' => $this->getTableCount('user_balance')
        ];
        
        // Compare total amounts
        $report['amount_comparison'] = $this->compareAmounts();
        
        return $report;
    }

    private function getTableCount(string $tableName): int
    {
        $connection = $this->entityManager->getConnection();
        try {
            $result = $connection->executeQuery("SELECT COUNT(*) FROM {$tableName}")->fetchOne();
            return (int) $result;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function compareAmounts(): array
    {
        $connection = $this->entityManager->getConnection();
        
        try {
            // Sum from legacy tables
            $legacyIncoming = $connection->executeQuery(
                'SELECT COALESCE(SUM(amount_cents), 0) FROM incoming_payment'
            )->fetchOne();
            
            $legacyTransactions = $connection->executeQuery(
                'SELECT COALESCE(SUM(amount_cents), 0) FROM catering_credit_transaction'
            )->fetchOne();
            
            // Sum from new tables
            $unifiedTotal = $connection->executeQuery(
                'SELECT COALESCE(SUM(amount), 0) FROM user_transaction'
            )->fetchOne();
            
            return [
                'legacy_incoming_total' => (int) $legacyIncoming,
                'legacy_transactions_total' => (int) $legacyTransactions,
                'unified_total' => (int) $unifiedTotal,
                'difference' => (int) $unifiedTotal - ((int) $legacyIncoming + (int) $legacyTransactions)
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
