<?php

namespace App\Repository;

use App\Entity\CateringTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<CateringTransaction>
 */
class CateringTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CateringTransaction::class);
    }

    public function save(CateringTransaction $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CateringTransaction $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find transactions by user
     */
    public function findByUser(UuidInterface $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending transactions (unmatched payments)
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', CateringTransaction::STATUS_PENDING)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find matched but unprocessed transactions
     */
    public function findMatchedUnprocessed(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->setParameter('status', CateringTransaction::STATUS_MATCHED)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find potential duplicate payments
     */
    public function findPotentialDuplicates(UuidInterface $user, int $amount, string $source, \DateTime $since): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.amount = :amount')
            ->andWhere('t.source = :source')
            ->andWhere('t.createdAt >= :since')
            ->andWhere('t.isDuplicate = false') // Don't match against already marked duplicates
            ->setParameter('user', $user)
            ->setParameter('amount', $amount)
            ->setParameter('source', $source)
            ->setParameter('since', $since)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent payments that might indicate a duplicate
     * Used to detect if a payment was already manually processed
     */
    public function findRecentProcessedPayments(UuidInterface $user, int $amount, \DateTime $since): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.amount = :amount')
            ->andWhere('t.type IN (:types)')
            ->andWhere('t.status = :status')
            ->andWhere('t.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('amount', $amount)
            ->setParameter('types', [
                CateringTransaction::TYPE_PAYMENT_RECEIVED,
                CateringTransaction::TYPE_CREDIT_ADJUSTMENT
            ])
            ->setParameter('status', CateringTransaction::STATUS_PROCESSED)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate balance for a user based on all transactions
     */
    public function calculateUserBalance(UuidInterface $user): int
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount) as total')
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->andWhere('t.isDuplicate = false')
            ->setParameter('user', $user)
            ->setParameter('status', CateringTransaction::STATUS_PROCESSED)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Find transactions by external ID (for duplicate detection)
     */
    public function findByExternalId(string $externalId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.externalId = :externalId')
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getResult();
    }
}
