<?php

namespace App\Tests\Controller;

use App\Entity\ChangeEvent;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\BillingPlan;
use App\Enum\NotificationFrequency;
use App\Message\SendInstantChangeEventEmailMessage;
use App\Service\ApiTokenManager;
use App\Tests\DatabaseTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiChangeEventControllerTest extends WebTestCase
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
        $this->resetAsyncTransport();
    }

    public function testMissingBearerTokenIsRejected(): void
    {
        $this->client->request('POST', '/api/events', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidBearerTokenIsRejected(): void
    {
        $this->client->request('POST', '/api/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ndb_invalid',
        ], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testValidEventIsPersistedAndInstantMessageIsQueued(): void
    {
        $token = 'ndb_test_token';
        $this->createProject($token, NotificationFrequency::Instant, false);

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

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->entityManager->getRepository(ChangeEvent::class)->count([]));

        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent());
        self::assertContainsOnlyInstancesOf(SendInstantChangeEventEmailMessage::class, $messages);
    }

    public function testAiSummaryIsGeneratedWhenEnabled(): void
    {
        $token = 'ndb_ai_token';
        $this->createProject($token, NotificationFrequency::Off, true);

        $this->client->request('POST', '/api/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'tableName' => 'orders',
            'recordId' => 7,
            'eventType' => 'created',
            'oldValues' => [],
            'newValues' => ['status' => 'paid'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($data['humanSummary']);
        self::assertStringContainsString('Created record 7 in orders', $data['humanSummary']);
    }

    public function testAiSummaryIsNotGeneratedForFreeUser(): void
    {
        $token = 'ndb_free_ai_token';
        $this->createProject($token, NotificationFrequency::Off, true, BillingPlan::Free);

        $this->client->request('POST', '/api/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'tableName' => 'orders',
            'recordId' => 9,
            'eventType' => 'created',
            'oldValues' => [],
            'newValues' => ['status' => 'paid'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($data['humanSummary']);
    }

    public function testWeeklyEventLimitRejectsAdditionalEvents(): void
    {
        $token = 'ndb_limited_token';
        $project = $this->createProject($token, NotificationFrequency::Off, false, BillingPlan::Free);

        for ($i = 0; $i < User::FREE_WEEKLY_EVENT_LIMIT; ++$i) {
            $event = (new ChangeEvent())
                ->setProject($project)
                ->setTableName('users')
                ->setRecordId((string) $i)
                ->setEventType(\App\Enum\ChangeEventType::Updated)
                ->setOldValues([])
                ->setNewValues(['count' => $i]);
            $this->entityManager->persist($event);
        }
        $this->entityManager->flush();

        $this->client->request('POST', '/api/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'tableName' => 'users',
            'recordId' => 'over-limit',
            'eventType' => 'updated',
            'oldValues' => [],
            'newValues' => ['status' => 'blocked'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(429);
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Weekly event limit reached.', $data['error']);
        self::assertSame(User::FREE_WEEKLY_EVENT_LIMIT, $data['limit']);
    }

    public function testAiSummaryIsSkippedWhenProWeeklySummaryLimitIsReached(): void
    {
        $token = 'ndb_ai_limit_token';
        $project = $this->createProject($token, NotificationFrequency::Off, true, BillingPlan::Pro);

        for ($i = 0; $i < User::PRO_WEEKLY_AI_SUMMARY_LIMIT; ++$i) {
            $event = (new ChangeEvent())
                ->setProject($project)
                ->setTableName('orders')
                ->setRecordId((string) $i)
                ->setEventType(\App\Enum\ChangeEventType::Updated)
                ->setOldValues([])
                ->setNewValues(['status' => 'paid'])
                ->setHumanSummary('Existing summary');
            $this->entityManager->persist($event);
        }
        $this->entityManager->flush();

        $this->client->request('POST', '/api/events', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'tableName' => 'orders',
            'recordId' => 'summary-over-limit',
            'eventType' => 'updated',
            'oldValues' => ['status' => 'pending'],
            'newValues' => ['status' => 'paid'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($data['humanSummary']);
    }

    private function createProject(
        string $plainToken,
        NotificationFrequency $frequency,
        bool $aiSummariesEnabled,
        BillingPlan $plan = BillingPlan::Pro,
    ): Project
    {
        $user = (new User())->setEmail(uniqid('user_', true).'@example.com');
        if (!$aiSummariesEnabled) {
            $plan = BillingPlan::Free;
        }

        $user
            ->setPlan($plan)
            ->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));

        $project = (new Project())
            ->setName('Test project')
            ->setOwner($user)
            ->setApiTokenHash($this->apiTokenManager->hashToken($plainToken))
            ->setApiTokenPrefix($this->apiTokenManager->prefixFor($plainToken))
            ->setNotificationFrequency($frequency)
            ->setAiSummariesEnabled($aiSummariesEnabled)
            ->setNotificationEmail('notify@example.com');

        $this->entityManager->persist($user);
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        return $project;
    }

    private function resetAsyncTransport(): void
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        if (method_exists($transport, 'reset')) {
            $transport->reset();
        }
    }
}
