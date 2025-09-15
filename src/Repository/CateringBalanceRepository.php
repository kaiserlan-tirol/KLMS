<?php

namespace App\Repository;

use App\Entity\CateringBalance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<CateringBalance>
 */
class CateringBalanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CateringBalance::class);
    }

    public function save(CateringBalance $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(CateringBalance $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find balance for a specific user
     */
    public function findByUser(UuidInterface $user): ?CateringBalance
    {
        return $this->createQueryBuilder('b')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get or create balance for a user
     */
    public function getOrCreateForUser(UuidInterface $user): CateringBalance
    {
        $balance = $this->findByUser($user);
        
        if (!$balance) {
            $balance = new CateringBalance();
            $balance->setUser($user);
            $balance->setBalance(0);
            $this->save($balance, true);
        }
        
        return $balance;
    }

    /**
     * Find all users with positive balances
     */
    public function findPositiveBalances(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.balance > 0')
            ->orderBy('b.balance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all users with negative balances
     */
    public function findNegativeBalances(): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.balance < 0')
            ->orderBy('b.balance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total sum of all balances
     */
    public function getTotalBalance(): int
    {
        $result = $this->createQueryBuilder('b')
            ->select('SUM(b.balance) as total')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Find balances that haven't been updated recently (for cleanup/verification)
     */
    public function findStaleBalances(\DateTime $before): array
    {
        return $this->createQueryBuilder('b')
            ->where('b.updatedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
