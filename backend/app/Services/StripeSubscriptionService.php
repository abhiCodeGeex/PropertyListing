<?php

namespace App\Services;

use Carbon\Carbon;
use RuntimeException;
use Stripe\StripeClient;

class StripeSubscriptionService
{
    public function scheduleCancellation(string $subscriptionId, Carbon $cancelAt): void
    {
        $stripe = new StripeClient(config('services.stripe.secret'));

        $stripe->subscriptions->update($subscriptionId, [
            'cancel_at' => $cancelAt->timestamp,
        ]);
    }

    public function ensureSubscriptionPrice(string $subscriptionId, string $priceId): void
    {
        $stripe = new StripeClient(config('services.stripe.secret'));
        $subscription = $stripe->subscriptions->retrieve($subscriptionId, []);
        $subscriptionItem = $subscription->items->data[0] ?? null;

        if (! $subscriptionItem) {
            throw new RuntimeException('Stripe subscription is missing a subscription item.');
        }

        $currentPriceId = $subscriptionItem->price->id ?? null;

        if ($currentPriceId === $priceId) {
            return;
        }

        $stripe->subscriptions->update($subscriptionId, [
            'items' => [
                [
                    'id' => $subscriptionItem->id,
                    'price' => $priceId,
                ],
            ],
            'proration_behavior' => 'none',
        ]);
    }
}
