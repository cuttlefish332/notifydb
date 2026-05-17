<?php

namespace App\Command;

use App\Enum\NotificationFrequency;
use App\Repository\ProjectRepository;
use App\Service\DigestNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:send-weekly-digests', description: 'Send weekly NotifyDB digests for projects with pending events.')]
final class SendWeeklyDigestsCommand extends Command
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly DigestNotificationService $digestNotificationService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sentDigests = 0;
        $markedEvents = 0;

        foreach ($this->projectRepository->findProjectsNeedingDigest(NotificationFrequency::Weekly) as $project) {
            $marked = $this->digestNotificationService->sendDigest($project, NotificationFrequency::Weekly);
            if ($marked > 0) {
                ++$sentDigests;
                $markedEvents += $marked;
            }
        }

        $output->writeln(sprintf('Sent %d weekly digest(s) and marked %d event(s) emailed.', $sentDigests, $markedEvents));

        return Command::SUCCESS;
    }
}
