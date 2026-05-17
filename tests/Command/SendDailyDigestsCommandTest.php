<?php

namespace App\Tests\Command;

use App\Command\SendDailyDigestsCommand;
use App\Command\SendWeeklyDigestsCommand;
use App\Entity\ChangeEvent;
use App\Entity\NotificationEmailLog;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\ChangeEventType;
use App\Enum\NotificationFrequency;
use App\Service\ApiTokenManager;
use App\Tests\DatabaseTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SendDailyDigestsCommandTest extends KernelTestCase
{
    use DatabaseTestTrait;

    private EntityManagerInterface $entityManager;
    private ApiTokenManager $apiTokenManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = $this->resetDatabase();
        $this->apiTokenManager = static::getContainer()->get(ApiTokenManager::class);
        $this->resetAsyncTransport();
    }

    public function testDailyDigestSendsAndMarksOnlyDailyUnemailedEvents(): void
    {
        $dailyProject = $this->createProject('Daily', NotificationFrequency::Daily, 'daily@example.com');
        $instantProject = $this->createProject('Instant', NotificationFrequency::Instant, 'instant@example.com');
        $offProject = $this->createProject('Off', NotificationFrequency::Off, 'off@example.com');

        $dailyUnemailed = $this->createEvent($dailyProject);
        $dailyAlreadyEmailed = $this->createEvent($dailyProject, new \DateTimeImmutable('-1 day'));
        $instantUnemailed = $this->createEvent($instantProject);
        $offUnemailed = $this->createEvent($offProject);
        $this->entityManager->flush();

        $tester = new CommandTester(static::getContainer()->get(SendDailyDigestsCommand::class));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Sent 1 daily digest(s) and marked 1 event(s) emailed.', $tester->getDisplay());

        $this->entityManager->refresh($dailyUnemailed);
        $this->entityManager->refresh($dailyAlreadyEmailed);
        $this->entityManager->refresh($instantUnemailed);
        $this->entityManager->refresh($offUnemailed);

        self::assertNotNull($dailyUnemailed->getEmailedAt());
        self::assertNotNull($dailyAlreadyEmailed->getEmailedAt());
        self::assertNull($instantUnemailed->getEmailedAt());
        self::assertNull($offUnemailed->getEmailedAt());

        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertCount(1, array_filter($messages, static fn ($message) => $message instanceof SendEmailMessage));
    }

    public function testWeeklyDigestSendsOnlyWeeklyProjects(): void
    {
        $weeklyProject = $this->createProject('Weekly', NotificationFrequency::Weekly, 'weekly@example.com');
        $dailyProject = $this->createProject('Daily2', NotificationFrequency::Daily, 'daily2@example.com');

        $weeklyUnemailed = $this->createEvent($weeklyProject);
        $dailyUnemailed = $this->createEvent($dailyProject);
        $this->entityManager->flush();

        $tester = new CommandTester(static::getContainer()->get(SendWeeklyDigestsCommand::class));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Sent 1 weekly digest(s) and marked 1 event(s) emailed.', $tester->getDisplay());

        $this->entityManager->refresh($weeklyUnemailed);
        $this->entityManager->refresh($dailyUnemailed);

        self::assertNotNull($weeklyUnemailed->getEmailedAt());
        self::assertNull($dailyUnemailed->getEmailedAt());
    }

    public function testDigestIsHeldWhenWeeklyQuotaIsExhausted(): void
    {
        $project = $this->createProject('Quota', NotificationFrequency::Daily, 'quota@example.com');
        $event = $this->createEvent($project);

        for ($i = 0; $i < 5; ++$i) {
            $this->entityManager->persist((new NotificationEmailLog())
                ->setUser($project->getOwner())
                ->setProject($project)
                ->setType('daily')
                ->setEmail('quota@example.com'));
        }

        $this->entityManager->flush();

        $tester = new CommandTester(static::getContainer()->get(SendDailyDigestsCommand::class));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Sent 0 daily digest(s) and marked 0 event(s) emailed.', $tester->getDisplay());

        $this->entityManager->refresh($event);
        self::assertNull($event->getEmailedAt());
    }

    private function createProject(string $name, NotificationFrequency $frequency, string $email): Project
    {
        $plainToken = 'ndb_'.strtolower($name);
        $user = (new User())->setEmail(strtolower($name).'@owner.example');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));

        $project = (new Project())
            ->setName($name)
            ->setOwner($user)
            ->setApiTokenHash($this->apiTokenManager->hashToken($plainToken))
            ->setApiTokenPrefix($this->apiTokenManager->prefixFor($plainToken))
            ->setNotificationFrequency($frequency)
            ->setNotificationEmail($email);

        $this->entityManager->persist($user);
        $this->entityManager->persist($project);

        return $project;
    }

    private function createEvent(Project $project, ?\DateTimeImmutable $emailedAt = null): ChangeEvent
    {
        $event = (new ChangeEvent())
            ->setProject($project)
            ->setTableName('accounts')
            ->setRecordId((string) random_int(1, 9999))
            ->setEventType(ChangeEventType::Updated)
            ->setOldValues(['status' => 'trial'])
            ->setNewValues(['status' => 'paid'])
            ->setEmailedAt($emailedAt);

        $this->entityManager->persist($event);

        return $event;
    }

    private function resetAsyncTransport(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        if (method_exists($transport, 'reset')) {
            $transport->reset();
        }
    }
}
