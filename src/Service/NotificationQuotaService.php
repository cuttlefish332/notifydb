<?php

namespace App\Service;

use App\Entity\NotificationEmailLog;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ChangeEventRepository;
use App\Repository\NotificationEmailLogRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class NotificationQuotaService
{
    public function __construct(
        private NotificationEmailLogRepository $notificationEmailLogRepository,
        private ChangeEventRepository $changeEventRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function emailsSentThisWeek(User $user): int
    {
        return $this->notificationEmailLogRepository->countSentSince($user, $this->weekStart());
    }

    public function remainingThisWeek(User $user): int
    {
        return max(0, $user->getWeeklyEmailLimit() - $this->emailsSentThisWeek($user));
    }

    public function canSendEmail(User $user): bool
    {
        return $this->remainingThisWeek($user) > 0;
    }

    public function eventsAcceptedThisWeek(User $user): int
    {
        return $this->changeEventRepository->countForOwnerSince($user, $this->weekStart());
    }

    public function remainingEventsThisWeek(User $user): int
    {
        return max(0, $user->getWeeklyEventLimit() - $this->eventsAcceptedThisWeek($user));
    }

    public function canAcceptEvent(User $user): bool
    {
        return $this->remainingEventsThisWeek($user) > 0;
    }

    public function aiSummariesUsedThisWeek(User $user): int
    {
        return $this->changeEventRepository->countAiSummariesForOwnerSince($user, $this->weekStart());
    }

    public function remainingAiSummariesThisWeek(User $user): int
    {
        return max(0, $user->getWeeklyAiSummaryLimit() - $this->aiSummariesUsedThisWeek($user));
    }

    public function canCreateAiSummary(User $user): bool
    {
        return $this->remainingAiSummariesThisWeek($user) > 0;
    }

    public function recordEmail(User $user, ?Project $project, string $type, string $email): void
    {
        $log = (new NotificationEmailLog())
            ->setUser($user)
            ->setProject($project)
            ->setType($type)
            ->setEmail($email);

        $this->entityManager->persist($log);
    }

    public function weekStart(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now'))
            ->modify('monday this week')
            ->setTime(0, 0);
    }
}
