<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\StripeSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly UserRepository $userRepository,
        private readonly StripeSubscriptionService $stripeSubscriptionService,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $webhookSecret,
    ) {
    }

    #[Route('/stripe/webhook', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature'),
                $this->webhookSecret,
            );
        } catch (\UnexpectedValueException|SignatureVerificationException) {
            return new Response('Invalid Stripe webhook payload or signature.', Response::HTTP_BAD_REQUEST);
        }

        match ($event->type) {
            Event::CHECKOUT_SESSION_COMPLETED => $this->handleCheckoutSessionCompleted($event->data->object),
            Event::CUSTOMER_SUBSCRIPTION_CREATED,
            Event::CUSTOMER_SUBSCRIPTION_UPDATED => $this->handleSubscriptionUpdated($event->data->object),
            Event::CUSTOMER_SUBSCRIPTION_DELETED => $this->handleSubscriptionDeleted($event->data->object),
            default => null,
        };

        $this->entityManager->flush();

        return new Response('ok');
    }

    private function handleCheckoutSessionCompleted(Session $session): void
    {
        $userId = $session->metadata->notifydb_user_id ?? null;
        $user = is_numeric($userId) ? $this->userRepository->find((int) $userId) : null;
        if ($user === null) {
            return;
        }

        $user->setStripeCustomerId(is_string($session->customer) ? $session->customer : null);

        if (is_string($session->subscription)) {
            $subscription = $this->stripe->subscriptions->retrieve($session->subscription, [
                'expand' => ['items.data.price'],
            ]);
            $this->stripeSubscriptionService->applySubscription($user, $subscription);
        }
    }

    private function handleSubscriptionUpdated(Subscription $subscription): void
    {
        $customerId = is_string($subscription->customer) ? $subscription->customer : null;
        $user = $this->userRepository->findOneByStripeCustomerOrSubscription($customerId, $subscription->id);
        if ($user === null) {
            return;
        }

        $this->stripeSubscriptionService->applySubscription($user, $subscription);
    }

    private function handleSubscriptionDeleted(Subscription $subscription): void
    {
        $customerId = is_string($subscription->customer) ? $subscription->customer : null;
        $user = $this->userRepository->findOneByStripeCustomerOrSubscription($customerId, $subscription->id);
        if ($user === null) {
            return;
        }

        $this->stripeSubscriptionService->clearSubscription($user, $subscription->status);
    }
}
