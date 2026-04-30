<?php

namespace App\Services;

use App\Models\PropertyTenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripePayoutService
{
    public function __construct(
        private readonly StripeConnectService $stripeConnectService
    ) {}

    public function distributeStripePayment(
        PropertyTenant $tenancy,
        int $grossAmountInCents,
        string $sourceType,
        string $sourceId,
        string $paymentType,
        string $currency,
        ?string $sourceTransactionId = null
    ): void {
        if ($grossAmountInCents <= 0) {
            return;
        }

        $tenancy->loadMissing(['property.owner', 'property.manager']);
        $property = $tenancy->property;

        if (! $property || $property->payment_mode !== 'Credit/Debit Cards') {
            return;
        }

        $ownerPercent = (float) ($property->owner_commission_percent ?? 0);
        $managerPercent = (float) ($property->manager_commission_percent ?? 0);

        $resolution = $this->resolveTransferDetails($currency, $grossAmountInCents, $sourceTransactionId);
        $resolvedCurrency = $resolution['currency'];
        $resolvedAmount = $resolution['amount'];

        Log::debug('Stripe payout details resolved', [
            'tenancy_id' => $tenancy->id,
            'payment_type' => $paymentType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'original_currency' => strtolower($currency),
            'original_amount' => $grossAmountInCents,
            'resolved_currency' => $resolvedCurrency,
            'resolved_amount' => $resolvedAmount,
            'source_transaction_id' => $sourceTransactionId,
        ]);

        if (! $resolvedCurrency) {
            Log::warning('Stripe payout currency could not be resolved; skipping distribution to avoid transfer failure.', [
                'tenancy_id' => $tenancy->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'payment_type' => $paymentType,
                'source_transaction_id' => $sourceTransactionId,
            ]);

            return;
        }
        $currencyAdjustmentLogged = false;

        $allocations = [
            [
                'user' => $property->owner,
                'role' => 'owner',
                'amount' => $this->allocationAmount($resolvedAmount, $ownerPercent),
            ],
            [
                'user' => $property->manager,
                'role' => 'manager',
                'amount' => $this->allocationAmount($resolvedAmount, $managerPercent),
            ],
        ];

        foreach ($allocations as $allocation) {
            $user = $allocation['user'];
            $amount = $allocation['amount'];

            if (! $user instanceof User || $amount <= 0) {
                continue;
            }

            $status = $this->stripeConnectService->statusPayload($user);
            if (! ($status['ready_for_payouts'] ?? false)) {
                Log::warning('Stripe payout skipped because connected account is not ready.', [
                    'user_id' => $user->id,
                    'role' => $allocation['role'],
                    'property_id' => $property->id,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]);

                continue;
            }

            $payload = [
                'amount' => $amount,
                'currency' => $resolvedCurrency,
                'destination' => $user->stripe_connect_account_id,
                'metadata' => [
                    'tenancy_id' => (string) $tenancy->id,
                    'property_id' => (string) $property->id,
                    'recipient_user_id' => (string) $user->id,
                    'recipient_role' => $allocation['role'],
                    'payment_type' => $paymentType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ],
            ];

            if ($sourceTransactionId) {
                if (! $currencyAdjustmentLogged && strtolower($currency) !== $resolvedCurrency) {
                    Log::info('Adjusted Stripe transfer currency to match balance transaction.', [
                        'original_currency' => strtolower($currency),
                        'resolved_currency' => $resolvedCurrency,
                        'source_transaction_id' => $sourceTransactionId,
                        'property_id' => $property->id,
                    ]);
                    $currencyAdjustmentLogged = true;
                }

                $payload['source_transaction'] = $sourceTransactionId;
            }

            $transfer = $this->stripeClient()->transfers->create(
                $payload,
                [
                    'idempotency_key' => sprintf(
                        'connect_transfer:%s:%s:%s:%s',
                        $sourceType,
                        $sourceId,
                        $allocation['role'],
                        $user->id
                    ),
                ]
            );

            Log::info('Stripe transfer created successfully', [
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'currency' => $resolvedCurrency,
                'destination' => $user->stripe_connect_account_id,
                'role' => $allocation['role'],
                'user_id' => $user->id,
            ]);
        }
    }

    private function resolveTransferDetails(string $configuredCurrency, int $configuredAmount, ?string $sourceTransactionId): array
    {
        $res = [
            'currency' => strtolower(trim($configuredCurrency)),
            'amount' => $configuredAmount,
        ];

        if (! $sourceTransactionId) {
            return $res;
        }

        // Sometimes the balance transaction is not immediately linked to the charge.
        // We try a few times to ensure we get the settlement currency (e.g. USD) instead of the charge currency (e.g. INR).
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $charge = $this->stripeClient()->charges->retrieve($sourceTransactionId, []);
                $balanceTransactionId = (string) ($charge->balance_transaction ?? '');

                if ($balanceTransactionId !== '') {
                    $balanceTxn = $this->stripeClient()->balanceTransactions->retrieve($balanceTransactionId, []);
                    $balanceCurrency = strtolower((string) ($balanceTxn->currency ?? ''));
                    $balanceAmount = (int) ($balanceTxn->amount ?? 0);

                    if ($balanceCurrency !== '' && $balanceAmount > 0) {
                        Log::debug('Resolved details from balance transaction', [
                            'source_transaction_id' => $sourceTransactionId,
                            'balance_transaction_id' => $balanceTransactionId,
                            'balance_currency' => $balanceCurrency,
                            'balance_amount' => $balanceAmount,
                            'attempt' => $attempts + 1,
                        ]);

                        return [
                            'currency' => $balanceCurrency,
                            'amount' => $balanceAmount,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Error during Stripe detail resolution attempt', [
                    'attempt' => $attempts + 1,
                    'error' => $e->getMessage(),
                ]);
            }

            $attempts++;
            if ($attempts < $maxAttempts) {
                usleep(500000); // Wait 0.5 seconds before retrying
            }
        }

        // If we reach here, we failed to get a balance transaction. 
        // We will try the charge details as a last resort, but this might fail if the currency differs from settlement.
        try {
            $charge = $this->stripeClient()->charges->retrieve($sourceTransactionId, []);
            $chargeCurrency = strtolower((string) ($charge->currency ?? ''));
            $chargeAmount = (int) ($charge->amount ?? 0);

            if ($chargeCurrency !== '' && $chargeAmount > 0) {
                Log::debug('Resolved details from charge object (Fallback)', [
                    'source_transaction_id' => $sourceTransactionId,
                    'charge_currency' => $chargeCurrency,
                    'charge_amount' => $chargeAmount,
                ]);

                return [
                    'currency' => $chargeCurrency,
                    'amount' => $chargeAmount,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to resolve Stripe charge details; falling back to configured values.', [
                'source_transaction_id' => $sourceTransactionId,
                'error' => $e->getMessage(),
            ]);
        }

        return $res;
    }

    private function allocationAmount(int $grossAmountInCents, float $percent): int
    {
        if ($percent <= 0) {
            return 0;
        }

        return (int) round($grossAmountInCents * ($percent / 100), 0);
    }

    private function stripeClient(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
