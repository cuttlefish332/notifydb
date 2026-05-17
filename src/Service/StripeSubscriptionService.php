<?php

namespace App\Service;

use App\Entity\User;
use App\Enum\BillingPlan;
use Stripe\StripeObject;

final readonly class StripeSubscriptionService
{
    public function __construct(private string $proPriceId)
    {
    }

    public function applySubscription(User $user, StripeObject|array $subscription): void
    {
        $status = $this->value($subscription, 'status');
        $subscriptionId = $this->value($subscription, 'id');
        $customerId = $this->value($subscription, 'customer');
        $currentPeriodEnd = $this->value($subscription, 'current_period_end');
        $cancelAtPeriodEnd = $this->value($subscription, 'cancel_at_period_end');

        $user
            ->setStripeSubscriptionId(is_string($subscriptionId) ? $subscriptionId : null)
            ->setStripeSubscriptionStatus(is_string($status) ? $status : null)
            ->setStripeCustomerId(is_string($customerId) ? $customerId : $user->getStripeCustomerId())
            ->setStripeCurrentPeriodEnd(is_numeric($currentPeriodEnd) ? (new \DateTimeImmutable())->setTimestamp((int) $currentPeriodEnd) : null)
            ->setStripeCancelAtPeriodEnd($cancelAtPeriodEnd === true)
            ->setPlan($this->isActiveProSubscription($subscription) ? BillingPlan::Pro : BillingPlan::Free);
    }

    public function clearSubscription(User $user, ?string $status = null): void
    {
        $user
            ->setPlan(BillingPlan::Free)
            ->setStripeSubscriptionId(null)
            ->setStripeSubscriptionStatus($status)
            ->setStripeCurrentPeriodEnd(null)
            ->setStripeCancelAtPeriodEnd(false);
    }

    private function isActiveProSubscription(StripeObject|array $subscription): bool
    {
        $status = $this->value($subscription, 'status');
        if (!in_array($status, ['active', 'trialing'], true)) {
            return false;
        }

        foreach ($this->subscriptionItems($subscription) as $item) {
            $price = $this->value($item, 'price');
            if ($this->value($price, 'id') === $this->proPriceId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<int, mixed>
     */
    private function subscriptionItems(StripeObject|array $subscription): iterable
    {
        $items = $this->value($subscription, 'items');
        $data = $this->value($items, 'data');

        return is_iterable($data) ? $data : [];
    }

    private function value(mixed $source, string $key): mixed
    {
        if ($source instanceof StripeObject) {
            return $source->{$key} ?? null;
        }

        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return null;
    }
}
