<?php

namespace App\Repository;

use App\Entity\Project;
use App\Enum\NotificationFrequency;
use App\Service\ApiTokenManager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ApiTokenManager $apiTokenManager,
    ) {
        parent::__construct($registry, Project::class);
    }

    public function findOneByPlainApiToken(string $plainToken): ?Project
    {
        return $this->findOneBy(['apiTokenHash' => $this->apiTokenManager->hashToken($plainToken)]);
    }

    /**
     * @return list<Project>
     */
    public function findProjectsNeedingDigest(NotificationFrequency $frequency): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.changeEvents', 'e')
            ->andWhere('p.notificationFrequency = :frequency')
            ->andWhere('p.notificationEmail IS NOT NULL')
            ->andWhere('e.emailedAt IS NULL')
            ->setParameter('frequency', $frequency)
            ->groupBy('p.id')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
