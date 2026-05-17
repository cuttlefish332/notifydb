<?php

namespace App\Service;

use App\Entity\Project;
use App\Enum\NotificationFrequency;
use App\Repository\ChangeEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final readonly class DigestNotificationService
{
    public function __construct(
        private ChangeEventRepository $changeEventRepository,
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private ChangeEventFormatter $formatter,
        private NotificationQuotaService $quotaService,
        #[Autowire('%env(default:app.default_url:APP_URL)%')]
        private string $appUrl,
        #[Autowire('%env(default:mailer.default_from_email:MAILER_FROM_EMAIL)%')]
        private string $fromEmail,
        #[Autowire('%env(default:mailer.default_from_name:MAILER_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    public function sendDigest(Project $project, NotificationFrequency $frequency): int
    {
        $owner = $project->getOwner();
        $notificationEmail = $project->getNotificationEmail();
        if ($owner === null || $notificationEmail === null || !$this->quotaService->canSendEmail($owner)) {
            return 0;
        }

        $events = $this->changeEventRepository->findUnemailedForProject($project);
        if ($events === []) {
            return 0;
        }

        $label = $frequency === NotificationFrequency::Weekly ? 'weekly' : 'daily';
        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($notificationEmail)
            ->subject(sprintf('NotifyDB %s digest: %s', $label, $project->getName()))
            ->htmlTemplate('emails/daily_digest.html.twig')
            ->context([
                'project' => $project,
                'events' => $events,
                'formatter' => $this->formatter,
                'digestLabel' => ucfirst($label),
                'dashboardUrl' => rtrim($this->appUrl, '/').'/dashboard',
            ]);

        $this->mailer->send($email);

        $emailedAt = new \DateTimeImmutable();
        foreach ($events as $event) {
            $event->setEmailedAt($emailedAt);
        }

        $this->quotaService->recordEmail($owner, $project, $label, $notificationEmail);
        $this->entityManager->flush();

        return count($events);
    }
}
