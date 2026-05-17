<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationQuotaService;
use App\Service\StripeSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/billing')]
final class BillingController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly StripeClient $stripe,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationQuotaService $quotaService,
        private readonly StripeSubscriptionService $stripeSubscriptionService,
        #[Autowire('%env(default:app.default_url:APP_URL)%')]
        private readonly string $appUrl,
        #[Autowire('%env(STRIPE_PRO_PRICE_ID)%')]
        private readonly string $proPriceId,
    ) {
    }

    #[Route('', name: 'billing_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->currentUser();

        return $this->render('billing/index.html.twig', [
            'user' => $user,
            'sentThisWeek' => $this->quotaService->emailsSentThisWeek($user),
            'remainingThisWeek' => $this->quotaService->remainingThisWeek($user),
        ]);
    }

    #[Route('/checkout', name: 'billing_checkout', methods: ['POST'])]
    public function checkout(): RedirectResponse
    {
        $user = $this->currentUser();
        $customerId = $user->getStripeCustomerId();

        try {
            if ($customerId === null) {
                $customer = $this->stripe->customers->create([
                    'email' => $user->getEmail(),
                    'metadata' => ['notifydb_user_id' => (string) $user->getId()],
                ]);
                $customerId = $customer->id;
                $user->setStripeCustomerId($customerId);
                $this->entityManager->flush();
            }

            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items' => [[
                    'price' => $this->proPriceId,
                    'quantity' => 1,
                ]],
                'success_url' => rtrim($this->appUrl, '/').'/billing?checkout=success',
                'cancel_url' => rtrim($this->appUrl, '/').'/billing?checkout=cancelled',
                'metadata' => ['notifydb_user_id' => (string) $user->getId()],
                'subscription_data' => [
                    'metadata' => ['notifydb_user_id' => (string) $user->getId()],
                ],
            ]);
        } catch (ApiErrorException $exception) {
            $this->addFlash('error', 'Stripe could not start checkout: '.$exception->getMessage());

            return $this->redirectToRoute('billing_index');
        }

        return $this->redirect((string) $session->url);
    }

    #[Route('/cancel', name: 'billing_cancel', methods: ['POST'])]
    public function cancel(): RedirectResponse
    {
        $user = $this->currentUser();

        try {
            $subscriptionId = $this->resolveSubscriptionId($user);
            if ($subscriptionId === null) {
                $this->addFlash('warning', 'No active Stripe subscription was found yet. If checkout just completed, try again in a moment.');

                return $this->redirectToRoute('billing_index');
            }

            $subscription = $this->stripe->subscriptions->update($subscriptionId, [
                'cancel_at_period_end' => true,
                'expand' => ['items.data.price'],
            ]);
        } catch (ApiErrorException $exception) {
            $this->addFlash('error', 'Stripe could not schedule the cancellation: '.$exception->getMessage());

            return $this->redirectToRoute('billing_index');
        }

        $this->stripeSubscriptionService->applySubscription($user, $subscription);
        $this->entityManager->flush();
        $this->addFlash('success', 'Your Pro subscription will end at the close of the current billing period.');

        return $this->redirectToRoute('billing_index');
    }

    private function resolveSubscriptionId(User $user): ?string
    {
        $subscriptionId = $user->getStripeSubscriptionId();
        if ($subscriptionId !== null) {
            return $subscriptionId;
        }

        $customerId = $user->getStripeCustomerId();
        if ($customerId === null) {
            return null;
        }

        $subscriptions = $this->stripe->subscriptions->all([
            'customer' => $customerId,
            'price' => $this->proPriceId,
            'status' => 'all',
            'limit' => 10,
        ]);

        foreach ($subscriptions->data as $subscription) {
            if (is_string($subscription->id) && in_array($subscription->status, ['active', 'trialing'], true)) {
                return $subscription->id;
            }
        }

        return null;
    }

    #[Route('/resume', name: 'billing_resume', methods: ['POST'])]
    public function resume(): RedirectResponse
    {
        $user = $this->currentUser();
        $subscriptionId = $user->getStripeSubscriptionId();
        if ($subscriptionId === null) {
            $this->addFlash('warning', 'No active Stripe subscription was found.');

            return $this->redirectToRoute('billing_index');
        }

        try {
            $subscription = $this->stripe->subscriptions->update($subscriptionId, [
                'cancel_at_period_end' => false,
                'expand' => ['items.data.price'],
            ]);
        } catch (ApiErrorException $exception) {
            $this->addFlash('error', 'Stripe could not resume the subscription: '.$exception->getMessage());

            return $this->redirectToRoute('billing_index');
        }

        $this->stripeSubscriptionService->applySubscription($user, $subscription);
        $this->entityManager->flush();
        $this->addFlash('success', 'Your Pro subscription will continue.');

        return $this->redirectToRoute('billing_index');
    }

    #[Route('/portal', name: 'billing_portal', methods: ['POST'])]
    public function portal(): RedirectResponse
    {
        $user = $this->currentUser();
        if ($user->getStripeCustomerId() === null) {
            $this->addFlash('warning', 'Upgrade to Pro before opening the billing portal.');

            return $this->redirectToRoute('billing_index');
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $user->getStripeCustomerId(),
            'return_url' => rtrim($this->appUrl, '/').'/billing',
        ]);

        return $this->redirect((string) $session->url);
    }

    private function currentUser(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
