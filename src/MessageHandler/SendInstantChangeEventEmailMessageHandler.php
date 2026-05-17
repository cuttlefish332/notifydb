<?php

namespace App\MessageHandler;

use App\Message\SendInstantChangeEventEmailMessage;
use App\Repository\ChangeEventRepository;
use App\Service\ChangeEventFormatter;
use App\Service\NotificationQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final readonly class SendInstantChangeEventEmailMessageHandler
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

    public function __invoke(SendInstantChangeEventEmailMessage $message): void
    {
        $event = $this->changeEventRepository->find($message->changeEventId);
        if ($event === null || $event->getEmailedAt() !== null) {
            return;
        }

        $project = $event->getProject();
        if ($project === null || $project->getNotificationEmail() === null) {
            return;
        }

        $owner = $project->getOwner();
        if ($owner === null || !$this->quotaService->canSendEmail($owner)) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($project->getNotificationEmail())
            ->subject(sprintf('NotifyDB: %s changed', $project->getName()))
            ->htmlTemplate('emails/instant_change_event.html.twig')
            ->context([
                'project' => $project,
                'event' => $event,
                'summary' => $this->formatter->format($event),
                'dashboardUrl' => rtrim($this->appUrl, '/').'/dashboard',
            ]);

        $this->mailer->send($email);

        $this->quotaService->recordEmail($owner, $project, 'instant', $project->getNotificationEmail());
        $event->setEmailedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }
}
