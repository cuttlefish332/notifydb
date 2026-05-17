<?php

namespace App\Repository;

use App\Entity\ChangeEvent;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChangeEvent>
 */
class ChangeEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeEvent::class);
    }

    /**
     * @return list<ChangeEvent>
     */
    public function findUnemailedForProject(Project $project): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.project = :project')
            ->andWhere('e.emailedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForOwnerSince(User $owner, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.project', 'p')
            ->andWhere('p.owner = :owner')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('owner', $owner)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<ChangeEvent>
     */
    public function findRecentForProject(Project $project, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.project = :project')
            ->setParameter('project', $project)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countAiSummariesForOwnerSince(User $owner, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.project', 'p')
            ->andWhere('p.owner = :owner')
            ->andWhere('e.createdAt >= :since')
            ->andWhere('e.humanSummary IS NOT NULL')
            ->setParameter('owner', $owner)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
