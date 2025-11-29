<?php

namespace App\Repository;

use App\Entity\UserBalance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<UserBalance>
 */
class UserBalanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBalance::class);
    }

    public function save(UserBalance $balance): void
    {
        $this->getEntityManager()->persist($balance);
        $this->getEntityManager()->flush();
    }

    /**
     * Find or create balance record for a user
     */
    public function findOrCreateForUser(UuidInterface $user): UserBalance
    {
        $balance = $this->findOneBy(['user' => $user]);
        
        if (!$balance) {
            $balance = new UserBalance();
            $balance->setUser($user);
            $this->save($balance);
        }

        return $balance;
    }

    /**
     * Get users with positive catering balance
     */
    public function findUsersWithCateringCredit(): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.cateringBalance > 0')
            ->orderBy('b.cateringBalance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get users with negative balance (debt)
     */
    public function findUsersWithDebt(): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.totalBalance < 0')
            ->orderBy('b.totalBalance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get users with negative catering balance (debt specific to catering category)
     */
    public function findUsersWithNegativeCateringBalance(): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.cateringBalance < 0')
            ->orderBy('b.cateringBalance', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total platform balance statistics
     */
    public function getPlatformBalanceStats(): array
    {
        $result = $this->createQueryBuilder('b')
            ->select([
                'COUNT(b.id) as totalUsers',
                'SUM(b.cateringBalance) as totalCateringBalance',
                'SUM(b.shopBalance) as totalShopBalance',
                'SUM(b.totalBalance) as totalBalance',
                'AVG(b.cateringBalance) as avgCateringBalance',
                'AVG(b.totalBalance) as avgTotalBalance'
            ])
            ->getQuery()
            ->getSingleResult();

        return [
            'totalUsers' => (int) $result['totalUsers'],
            'totalCateringBalance' => (int) $result['totalCateringBalance'],
            'totalShopBalance' => (int) $result['totalShopBalance'],
            'totalBalance' => (int) $result['totalBalance'],
            'avgCateringBalance' => (int) $result['avgCateringBalance'],
            'avgTotalBalance' => (int) $result['avgTotalBalance'],
        ];
    }
}
