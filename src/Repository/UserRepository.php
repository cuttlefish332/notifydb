<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByStripeCustomerOrSubscription(?string $customerId, ?string $subscriptionId): ?User
    {
        $queryBuilder = $this->createQueryBuilder('u');

        if ($customerId !== null && $subscriptionId !== null) {
            return $queryBuilder
                ->andWhere('u.stripeCustomerId = :customerId OR u.stripeSubscriptionId = :subscriptionId')
                ->setParameter('customerId', $customerId)
                ->setParameter('subscriptionId', $subscriptionId)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($customerId !== null) {
            return $this->findOneBy(['stripeCustomerId' => $customerId]);
        }

        if ($subscriptionId !== null) {
            return $this->findOneBy(['stripeSubscriptionId' => $subscriptionId]);
        }

        return null;
    }
}
