<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Price;

class StripePriceService
{
    public function resolveRecurringPriceId(
        string $productId,
        string $currency,
        int $unitAmount,
        array $metadata = [],
        ?string $existingPriceId = null
    ): string {
        if ($existingPriceId) {
            try {
                $existingPrice = Price::retrieve($existingPriceId, []);

                if ($this->priceMatches($existingPrice, $productId, $unitAmount, $currency)) {
                    return $existingPrice->id;
                }

                Log::warning('Stored Stripe price no longer matches tenancy billing details', [
                    'stored_price_id' => $existingPriceId,
                    'configured_currency' => $currency,
                    'configured_unit_amount' => $unitAmount,
                    'stripe_currency' => strtolower((string) ($existingPrice->currency ?? '')),
                    'stripe_unit_amount' => $existingPrice->unit_amount ?? null,
                    'stripe_product' => $existingPrice->product ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Stored Stripe price could not be retrieved; a replacement price will be resolved', [
                    'stored_price_id' => $existingPriceId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $matchingPrice = collect(Price::all([
            'product' => $productId,
            'active' => true,
            'limit' => 100,
        ])->data)->first(
            fn ($price) => $this->priceMatches($price, $productId, $unitAmount, $currency)
        );

        if ($matchingPrice) {
            return $matchingPrice->id;
        }

        return Price::create([
            'product' => $productId,
            'currency' => $currency,
            'unit_amount' => $unitAmount,
            'recurring' => ['interval' => 'month'],
            'metadata' => $metadata,
        ])->id;
    }

    private function priceMatches(object $price, string $productId, int $unitAmount, string $currency): bool
    {
        return ($price->active ?? false) === true
            && ($price->product ?? null) === $productId
            && ($price->unit_amount ?? null) === $unitAmount
            && strtolower((string) ($price->currency ?? '')) === $currency
            && (($price->recurring->interval ?? null) === 'month');
    }
}
