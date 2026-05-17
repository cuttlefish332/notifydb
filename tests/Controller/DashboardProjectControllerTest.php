<?php

namespace App\Tests\Controller;

use App\Entity\ChangeEvent;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\BillingPlan;
use App\Enum\ChangeEventType;
use App\Enum\NotificationFrequency;
use App\Service\ApiTokenManager;
use App\Tests\DatabaseTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardProjectControllerTest extends WebTestCase
{
    use DatabaseTestTrait;

    private EntityManagerInterface $entityManager;
    private ApiTokenManager $apiTokenManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDatabase();
        $this->apiTokenManager = static::getContainer()->get(ApiTokenManager::class);
    }

    public function testProjectCreationShowsOneTimeTokenAndGeneratedSnippets(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);
        $csrfToken = $this->csrfTokenFromDashboard('input[name="_csrf_token"]');

        $this->client->request('POST', '/dashboard/projects', [
            '_csrf_token' => $csrfToken,
            'name' => 'Website API',
            'notificationEmail' => 'alerts@example.com',
            'notificationFrequency' => 'daily',
            'aiSummariesEnabled' => '1',
        ]);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('One-time API token', $content);
        self::assertStringContainsString('curl -X POST http://localhost/api/events', $content);
        self::assertStringContainsString("await fetch('http://localhost/api/events'", $content);

        preg_match('/ndb_[a-f0-9]{64}/', $content, $matches);
        self::assertNotEmpty($matches);
        $plainToken = $matches[0];

        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['name' => 'Website API']);
        self::assertInstanceOf(Project::class, $project);
        self::assertSame($user->getId(), $project->getOwner()?->getId());
        self::assertSame($this->apiTokenManager->hashToken($plainToken), $project->getApiTokenHash());
        self::assertSame($this->apiTokenManager->prefixFor($plainToken), $project->getApiTokenPrefix());
        self::assertSame(NotificationFrequency::Daily, $project->getNotificationFrequency());
        self::assertTrue($project->isAiSummariesEnabled());
    }

    public function testRegeneratingTokenInvalidatesOldTokenAndShowsNewToken(): void
    {
        $oldToken = 'ndb_old_dashboard_token';
        $user = $this->createUser();
        $project = $this->createProject($user, $oldToken);
        $this->client->loginUser($user);
        $csrfToken = $this->csrfTokenFromDashboard(sprintf('form[action="/dashboard/projects/%d/regenerate-token"] input[name="_csrf_token"]', $project->getId()));

        $this->client->request('POST', sprintf('/dashboard/projects/%d/regenerate-token', $project->getId()), [
            '_csrf_token' => $csrfToken,
        ]);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        preg_match('/ndb_[a-f0-9]{64}/', $content, $matches);
        self::assertNotEmpty($matches);
        $newToken = $matches[0];
        self::assertNotSame($oldToken, $newToken);

        $updatedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        self::assertInstanceOf(Project::class, $updatedProject);
        self::assertSame($this->apiTokenManager->hashToken($newToken), $updatedProject->getApiTokenHash());

        $this->postTestEvent($oldToken);
        self::assertResponseStatusCodeSame(401);

        $this->postTestEvent($newToken);
        self::assertResponseStatusCodeSame(201);
    }

    public function testDashboardChecklistAndRecentEventsReflectAcceptedEvents(): void
    {
        $token = 'ndb_recent_dashboard_token';
        $user = $this->createUser(BillingPlan::Pro);
        $project = $this->createProject($user, $token);
        $event = (new ChangeEvent())
            ->setProject($project)
            ->setTableName('users')
            ->setRecordId('42')
            ->setEventType(ChangeEventType::Updated)
            ->setOldValues(['email' => 'old@example.com'])
            ->setNewValues(['email' => 'new@example.com'])
            ->setHumanSummary('Email changed');
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Connected', $content);
        self::assertStringContainsString('Email changed', $content);
        self::assertStringContainsString('users', $content);
        self::assertStringContainsString('42', $content);
    }

    private function postTestEvent(string $token): void
    {
        $this->client->request('POST', '/api/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'tableName' => 'users',
            'recordId' => '42',
            'eventType' => 'updated',
            'oldValues' => ['email' => 'old@example.com'],
            'newValues' => ['email' => 'new@example.com'],
        ], JSON_THROW_ON_ERROR));
    }

    private function csrfTokenFromDashboard(string $selector): string
    {
        $crawler = $this->client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter($selector)->attr('value');
    }

    private function createUser(BillingPlan $plan = BillingPlan::Free): User
    {
        $user = (new User())
            ->setEmail(uniqid('dashboard_', true).'@example.com')
            ->setPlan($plan);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createProject(User $user, string $plainToken): Project
    {
        $project = (new Project())
            ->setName('Dashboard project')
            ->setOwner($user)
            ->setApiTokenHash($this->apiTokenManager->hashToken($plainToken))
            ->setApiTokenPrefix($this->apiTokenManager->prefixFor($plainToken))
            ->setNotificationFrequency(NotificationFrequency::Off)
            ->setAiSummariesEnabled(false)
            ->setNotificationEmail('alerts@example.com');

        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }
}
