<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\BillingPlan;
use App\Tests\DatabaseTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StripeWebhookControllerTest extends WebTestCase
{
    use DatabaseTestTrait;

    private const WEBHOOK_SECRET = 'whsec_change_me';
    private const PRO_PRICE_ID = 'price_1TY8BVEB11HGdI9vMns5LrXm';

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = $this->resetDatabase();
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $payload = json_encode(['id' => 'evt_bad', 'type' => 'customer.subscription.updated'], JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=123,v1=bad',
        ], content: $payload);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSubscriptionUpdatedActivatesProPlan(): void
    {
        $user = $this->createUser();
        $user
            ->setStripeCustomerId('cus_test_123')
            ->setStripeSubscriptionId('sub_test_123');
        $this->entityManager->flush();

        $payload = $this->eventPayload('customer.subscription.updated', [
            'id' => 'sub_test_123',
            'object' => 'subscription',
            'customer' => 'cus_test_123',
            'status' => 'active',
            'current_period_end' => 1_800_000_000,
            'cancel_at_period_end' => true,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test_123',
                    'object' => 'subscription_item',
                    'price' => [
                        'id' => self::PRO_PRICE_ID,
                        'object' => 'price',
                    ],
                ]],
            ],
        ]);

        $this->client->request('POST', '/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signatureFor($payload),
        ], content: $payload);

        self::assertResponseIsSuccessful();
        $this->entityManager->refresh($user);
        self::assertSame(BillingPlan::Pro, $user->getPlan());
        self::assertSame('active', $user->getStripeSubscriptionStatus());
        self::assertSame('sub_test_123', $user->getStripeSubscriptionId());
        self::assertTrue($user->isStripeCancelAtPeriodEnd());
    }

    public function testSubscriptionDeletedDowngradesToFree(): void
    {
        $user = $this->createUser(BillingPlan::Pro);
        $user
            ->setStripeCustomerId('cus_test_456')
            ->setStripeSubscriptionId('sub_test_456')
            ->setStripeSubscriptionStatus('active');
        $this->entityManager->flush();

        $payload = $this->eventPayload('customer.subscription.deleted', [
            'id' => 'sub_test_456',
            'object' => 'subscription',
            'customer' => 'cus_test_456',
            'status' => 'canceled',
            'cancel_at_period_end' => false,
            'items' => [
                'object' => 'list',
                'data' => [],
            ],
        ]);

        $this->client->request('POST', '/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signatureFor($payload),
        ], content: $payload);

        self::assertResponseIsSuccessful();
        $this->entityManager->refresh($user);
        self::assertSame(BillingPlan::Free, $user->getPlan());
        self::assertNull($user->getStripeSubscriptionId());
        self::assertSame('canceled', $user->getStripeSubscriptionStatus());
        self::assertFalse($user->isStripeCancelAtPeriodEnd());
    }

    /**
     * @param array<string, mixed> $object
     */
    private function eventPayload(string $type, array $object): string
    {
        return json_encode([
            'id' => 'evt_'.str_replace('.', '_', $type),
            'object' => 'event',
            'api_version' => '2025-10-29',
            'created' => 1_800_000_000,
            'data' => ['object' => $object],
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => $type,
        ], JSON_THROW_ON_ERROR);
    }

    private function signatureFor(string $payload): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }

    private function createUser(BillingPlan $plan = BillingPlan::Free): User
    {
        $user = (new User())
            ->setEmail(uniqid('billing_', true).'@example.com')
            ->setPlan($plan);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'password123'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
