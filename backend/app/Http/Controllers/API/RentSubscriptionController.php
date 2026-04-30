<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use App\Services\LateFeePolicyService;
use App\Services\NotificationService;
use App\Services\PaymentAttemptService;
use App\Services\StripePriceService;
use App\Services\StripeSubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Stripe;
use Stripe\Subscription;

class RentSubscriptionController extends Controller
{
    public function __construct(
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly StripePriceService $stripePriceService,
        private readonly StripeSubscriptionService $stripeSubscriptionService
    ) {}

    private function isoDateTimeOrNull(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function tenantTenancyQuery()
    {
        return PropertyTenant::with(['property.owner', 'property.manager', 'tenant'])
            ->where('tenant_id', auth()->id());
    }

    private function getTenantTenancyOrFail(int|string $tenancyId): PropertyTenant
    {
        return $this->tenantTenancyQuery()->findOrFail($tenancyId);
    }

    private function ensureRentDeedReady(PropertyTenant $tenancy): void
    {
        $rentDeed = $tenancy->resolveRentDeed();

        if (! $rentDeed) {
            throw ValidationException::withMessages([
                'tenancy_id' => ['Rent deed is not created yet for this tenancy. Payments are unavailable until it is created.'],
            ]);
        }

        $tenancy->setRelation('rentDeed', $rentDeed);
    }

    private function tenancyForSubscriptionStakeholderOrFail(int|string $tenancyId): PropertyTenant
    {
        $user = auth()->user();

        return PropertyTenant::with(['property.owner', 'property.manager', 'tenant'])
            ->where('id', $tenancyId)
            ->where(function ($query) use ($user) {
                if ($user->hasRole('super-admin')) {
                    return;
                }

                $query->where('tenant_id', $user->id)
                    ->orWhereHas('property', function ($property) use ($user) {
                        if ($user->hasRole('owner')) {
                            $property->where('user_id', $user->id);
                        }

                        if ($user->hasRole('property_manager')) {
                            $property->orWhere('manager_id', $user->id);
                        }
                    });
            })
            ->firstOrFail();
    }

    private function currentDueSchedules(PropertyTenant $tenancy)
    {
        return RentSchedule::where('tenancy_id', $tenancy->id)
            ->where('due_date', '<=', now()->endOfMonth())
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->get();
    }

    private function resolveMonthlyRent(PropertyTenant $tenancy): float
    {
        return $tenancy->resolvedMonthlyRentAmount();
    }

    private function stripeCurrency(): string
    {
        return strtolower((string) config('services.stripe.currency', 'inr'));
    }

    private function stripeMonthlyUnitAmount(PropertyTenant $tenancy): int
    {
        return (int) round($this->resolveMonthlyRent($tenancy) * 100);
    }

    private function ensureStripeConfigured(): array
    {
        $secret = config('services.stripe.secret');
        $productId = config('services.stripe.rent_product_id');
        $currency = $this->stripeCurrency();

        if (! $secret) {
            throw ValidationException::withMessages([
                'stripe' => ['Stripe secret is not configured.'],
            ]);
        }

        if (! $productId) {
            throw ValidationException::withMessages([
                'stripe' => ['Stripe rent product is not configured.'],
            ]);
        }

        if ($currency === '') {
            throw ValidationException::withMessages([
                'stripe' => ['Stripe currency is not configured.'],
            ]);
        }

        return [$secret, $productId, $currency];
    }

    private function resolveStripePriceId(PropertyTenant $tenancy, ?string $existingPriceId = null): string
    {
        [, $productId, $currency] = $this->ensureStripeConfigured();
        $monthlyRent = $this->resolveMonthlyRent($tenancy);
        $unitAmount = $this->stripeMonthlyUnitAmount($tenancy);

        return $this->stripePriceService->resolveRecurringPriceId(
            productId: $productId,
            currency: $currency,
            unitAmount: $unitAmount,
            metadata: [
                'source' => 'property_listing',
                'monthly_rent' => number_format($monthlyRent, 2, '.', ''),
                'tenancy_id' => (string) $tenancy->id,
            ],
            existingPriceId: $existingPriceId
        );
    }

    private function validateStripeCurrency(?string $actualCurrency, string $field): void
    {
        $expectedCurrency = $this->stripeCurrency();
        $normalizedActual = strtolower((string) $actualCurrency);

        if ($normalizedActual === '' || $normalizedActual !== $expectedCurrency) {
            throw ValidationException::withMessages([
                $field => [
                    sprintf(
                        'Stripe currency mismatch. Expected %s but received %s.',
                        strtoupper($expectedCurrency),
                        strtoupper($normalizedActual ?: 'unknown')
                    ),
                ],
            ]);
        }
    }

    private function validateExpectedAmount(?float $requestedAmount, float $expectedAmount): void
    {
        if ($requestedAmount === null) {
            return;
        }

        if (abs(round($requestedAmount, 2) - round($expectedAmount, 2)) > 0.01) {
            throw ValidationException::withMessages([
                'rent_amount' => [
                    'Invalid rent amount. Refresh the tenancy balance and retry.',
                ],
            ]);
        }
    }

    private function scheduleIdsMetadata(Collection $schedules): string
    {
        return $schedules->pluck('id')->implode(',');
    }

    private function lateFeeMetadata(array $lateFeeSummary): array
    {
        $firstItem = collect($lateFeeSummary['items'] ?? [])->first();

        return [
            'late_fee_total' => (string) ((int) round(((float) ($lateFeeSummary['total'] ?? 0)) * 100)),
            'late_fee_reference_date' => $this->lateFeeReferenceDate()->toDateString(),
            'late_fee_schedule_ids' => collect($lateFeeSummary['items'] ?? [])
                ->pluck('rent_schedule_id')
                ->filter()
                ->implode(','),
            'late_fee_unit_amount' => (string) ((int) round(((float) ($firstItem['amount'] ?? 0)) * 100)),
            'late_fee_policy_text' => (string) ($lateFeeSummary['policy_text'] ?? ''),
        ];
    }

    private function lateFeeReferenceDate(): Carbon
    {
        return now()->startOfDay();
    }

    private function lateFeeSummary(PropertyTenant $tenancy, Collection $schedules, ?Carbon $referenceDate = null): array
    {
        $tenancy->loadMissing('property');

        return app(LateFeePolicyService::class)->summarize(
            $schedules,
            $tenancy->property?->late_payment_penalty,
            ($referenceDate ?? $this->lateFeeReferenceDate())->copy()
        );
    }

    private function nextBillingAnchor(PropertyTenant $tenancy, Collection $coveredSchedules): Carbon
    {
        $dueDay = (int) ($tenancy->rentDeed?->rent_due_date ?? 1);
        $dueDay = max(1, min(28, $dueDay));

        $latestCoveredMonth = $coveredSchedules
            ->pluck('month')
            ->filter()
            ->map(fn ($month) => Carbon::parse($month)->startOfMonth())
            ->sort()
            ->last();

        $anchorBase = $latestCoveredMonth
            ? $latestCoveredMonth->copy()->addMonth()
            : now()->copy()->addMonth()->startOfMonth();

        return $anchorBase->day($dueDay)->startOfDay();
    }

    private function currentMonthEndCancellationAt(): Carbon
    {
        return now()->copy()->endOfMonth()->endOfDay();
    }

    private function ensureStripeBillingProfile(PropertyTenant $tenancy, \App\Models\User $user): void
    {
        $dirty = false;

        if (empty($tenancy->stripe_customer_id)) {
            $customer = Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'tenancy_id' => $tenancy->id,
                    'user_id' => $user->id,
                ],
            ]);

            $tenancy->stripe_customer_id = $customer->id;
            $dirty = true;
        }

        $resolvedPriceId = $this->resolveStripePriceId($tenancy, $tenancy->stripe_price_id);

        if ($tenancy->stripe_price_id !== $resolvedPriceId) {
            $tenancy->stripe_price_id = $resolvedPriceId;
            $dirty = true;
        }

        if ($dirty) {
            $tenancy->save();
        }
    }

    private function syncActiveSubscriptionPrice(PropertyTenant $tenancy): void
    {
        if (
            ! $tenancy->hasLiveSubscription()
            || empty($tenancy->stripe_price_id)
        ) {
            return;
        }

        $this->stripeSubscriptionService->ensureSubscriptionPrice(
            $tenancy->stripe_subscription_id,
            $tenancy->stripe_price_id
        );
    }

    private function attachAndPersistDefaultPaymentMethod(
        PropertyTenant $tenancy,
        string $paymentMethodId,
        bool $saveAsDefault = true
    ): PaymentMethod {
        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);

        if ($paymentMethod->customer && $paymentMethod->customer !== $tenancy->stripe_customer_id) {
            throw ValidationException::withMessages([
                'payment_method' => ['This payment method belongs to another customer.'],
            ]);
        }

        if ($paymentMethod->customer !== $tenancy->stripe_customer_id) {
            $paymentMethod->attach(['customer' => $tenancy->stripe_customer_id]);
            Log::info('Payment method attached', ['pm_id' => $paymentMethod->id]);
        }

        if ($saveAsDefault) {
            Customer::update($tenancy->stripe_customer_id, [
                'invoice_settings' => ['default_payment_method' => $paymentMethod->id],
            ]);
        }

        return $paymentMethod;
    }

    /**
     * Safe copy for API clients — full exception details stay in logs.
     */
    private function stripeUserFacingReason(\Throwable $e): string
    {
        if ($e instanceof CardException) {
            $msg = method_exists($e, 'getError') ? $e->getError()?->message : null;

            return $msg ? (string) $msg : 'Your card could not be processed. Try another payment method.';
        }

        if ($e instanceof RateLimitException) {
            return 'The payment provider is busy. Please wait a moment and try again.';
        }

        if ($e instanceof InvalidRequestException) {
            return 'Payment request could not be processed. Refresh the page and try again.';
        }

        if ($e instanceof ApiErrorException) {
            return 'Payment service is temporarily unavailable. Please try again shortly.';
        }

        return 'Something went wrong. Please try again.';
    }

    private function paymentErrorResponse(
        string $message,
        \Throwable $e,
        ?PropertyTenant $tenancy = null,
        ?string $type = null,
        ?float $amount = null
    ): JsonResponse {
        $internalReason = $e->getMessage();

        Log::error($message, [
            'reason' => $internalReason,
            'tenant_id' => $tenancy?->tenant_id ?? auth()->id(),
            'tenancy_id' => $tenancy?->id,
            'property_id' => $tenancy?->property_id,
            'type' => $type,
        ]);

        if ($tenancy && $type) {
            $this->paymentAttemptService->markIntentPaymentsFailed(
                tenancy: $tenancy,
                paymentIntentId: null,
                chargeId: null,
                reason: $internalReason,
                type: $type,
                amount: $amount
            );
        }

        return response()->json([
            'message' => $message,
            'reason' => $this->stripeUserFacingReason($e),
        ], 500);
    }

    private function subscriptionStatusPayload(PropertyTenant $tenancy): array
    {
        return [
            'subscription_id' => $tenancy->stripe_subscription_id,
            'status' => $tenancy->hasScheduledSubscriptionCancellation()
                ? 'scheduled_for_cancellation'
                : 'active',
            'subscription_active' => (int) $tenancy->subscription_active,
            'subscription_cancel_at' => $this->isoDateTimeOrNull($tenancy->subscription_cancel_at),
        ];
    }

    /* ===============================================================
     | INITIATE SUBSCRIPTION PAYMENT
     =============================================================== */
    public function subscribe(Request $request)
    {
        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
            'payment_method' => 'required|string',
            'rent_amount' => 'nullable|numeric|min:0.01',
            'save_card' => 'sometimes|boolean',
        ]);

        try {
            $tenancy = $this->getTenantTenancyOrFail($request->tenancy_id);
            $this->ensureRentDeedReady($tenancy);
            [$stripeSecret, , $currency] = $this->ensureStripeConfigured();
            $dueSchedules = $this->currentDueSchedules($tenancy);

            if ($dueSchedules->isEmpty()) {
                throw ValidationException::withMessages([
                    'tenancy_id' => ['No payable rent is currently due for this tenancy.'],
                ]);
            }

            $lateFeeSummary = $this->lateFeeSummary($tenancy, $dueSchedules);
            $expectedAmount = (float) $dueSchedules->sum('amount') + (float) $lateFeeSummary['total'];
            $this->validateExpectedAmount(
                $request->filled('rent_amount') ? (float) $request->rent_amount : null,
                $expectedAmount
            );

            $user = auth()->user();
            Stripe::setApiKey($stripeSecret);

            $response = DB::transaction(function () use ($tenancy, $request, $user, $dueSchedules, $expectedAmount, $lateFeeSummary, $currency) {
                $this->ensureStripeBillingProfile($tenancy, $user);

                Log::info('Using Stripe Customer', ['customer_id' => $tenancy->stripe_customer_id]);

                $paymentMethod = $this->attachAndPersistDefaultPaymentMethod(
                    tenancy: $tenancy,
                    paymentMethodId: $request->payment_method,
                    saveAsDefault: $request->boolean('save_card')
                );

                $intent = PaymentIntent::create([
                    'customer' => $tenancy->stripe_customer_id,
                    'payment_method' => $paymentMethod->id,
                    'amount' => (int) round($expectedAmount * 100),
                    'currency' => $currency,
                    'confirm' => false,
                    'setup_future_usage' => $request->boolean('save_card') ? 'off_session' : null,
                    'metadata' => [
                        'tenancy_id' => $tenancy->id,
                        'price_id' => $tenancy->stripe_price_id,
                        'type' => 'rent_subscription',
                        'schedule_ids' => $this->scheduleIdsMetadata($dueSchedules),
                        'expected_amount' => (string) ((int) round($expectedAmount * 100)),
                        ...$this->lateFeeMetadata($lateFeeSummary),
                    ],
                ]);

                $this->paymentAttemptService->createPendingPaymentsForSchedules(
                    tenancy: $tenancy,
                    schedules: $dueSchedules,
                    type: 'rent_deposit',
                    paymentIntentId: $intent->id
                );

                if ((float) $lateFeeSummary['total'] > 0) {
                    $this->paymentAttemptService->createPendingPayment(
                        tenancy: $tenancy,
                        type: 'late_fee',
                        amount: (float) $lateFeeSummary['total'],
                        paymentIntentId: $intent->id
                    );
                }

                Log::info('PaymentIntent created', ['intent_id' => $intent->id]);

                return [
                    'success' => true,
                    'client_secret' => $intent->client_secret,
                    'tenancy_id' => $tenancy->id,
                    'status' => $intent->status,
                ];
            });

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            $tenancy = null;

            if ($request->filled('tenancy_id')) {
                $tenancy = $this->tenantTenancyQuery()->find($request->tenancy_id);
            }

            return $this->paymentErrorResponse(
                message: 'Unable to initiate rent payment.',
                e: $e,
                tenancy: $tenancy,
                type: 'rent_deposit',
                amount: (float) ($request->rent_amount ?? 0)
            );
        }
    }

    public function activateAutoPay(Request $request)
    {
        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
            'payment_method' => 'required|string',
        ]);

        try {
            $tenancy = $this->getTenantTenancyOrFail($request->tenancy_id);
            $this->ensureRentDeedReady($tenancy);
            [$stripeSecret] = $this->ensureStripeConfigured();
            Stripe::setApiKey($stripeSecret);
            $this->ensureStripeBillingProfile($tenancy, auth()->user());

            if ($tenancy->hasLiveSubscription()) {
                $this->syncActiveSubscriptionPrice($tenancy);

                return response()->json(array_merge([
                    'success' => true,
                ], $this->subscriptionStatusPayload($tenancy)));
            }

            $response = DB::transaction(function () use ($tenancy, $request) {
                $user = auth()->user();
                $this->ensureStripeBillingProfile($tenancy, $user);

                $paymentMethod = $this->attachAndPersistDefaultPaymentMethod(
                    tenancy: $tenancy,
                    paymentMethodId: $request->payment_method,
                    saveAsDefault: true
                );

                $billingAnchor = $this->nextBillingAnchor($tenancy, collect());
                $cancelAt = $tenancy->end_date
                    ? Carbon::parse($tenancy->end_date)->endOfDay()->timestamp
                    : null;

                $subscription = Subscription::create([
                    'customer' => $tenancy->stripe_customer_id,
                    'items' => [
                        ['price' => $tenancy->stripe_price_id],
                    ],
                    'default_payment_method' => $paymentMethod->id,
                    'cancel_at' => $cancelAt,
                    'trial_end' => $billingAnchor->timestamp,
                    'metadata' => [
                        'tenancy_id' => $tenancy->id,
                        'property_id' => $tenancy->property_id,
                        'tenant_id' => $tenancy->tenant_id,
                        'source' => 'autopay_only',
                    ],
                ], [
                    'idempotency_key' => 'tenancy_autopay_'.$tenancy->id.'_'.$paymentMethod->id,
                ]);

                $tenancy->forceFill([
                    'stripe_subscription_id' => $subscription->id,
                    'subscription_active' => 1,
                    'subscription_cancel_at' => null,
                ])->save();

                NotificationService::notifyTenancyStakeholders(
                    $tenancy,
                    'subscription',
                    null,
                    [
                        'event' => 'activated',
                        'amount' => $this->resolveMonthlyRent($tenancy),
                    ]
                );

                return [
                    'success' => true,
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                    'subscription_active' => 1,
                    'subscription_cancel_at' => null,
                    'current_period_end' => $subscription->current_period_end,
                    'billing_anchor' => $billingAnchor->toIso8601String(),
                    'flow' => 'autopay_only',
                ];
            });

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            Log::critical('Future auto-pay activation failed', [
                'tenancy_id' => $request->tenancy_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Automatic rent payment could not be activated.',
                'reason' => $this->stripeUserFacingReason($e),
            ], 500);
        }
    }

    /* ===============================================================
     | CREATE SUBSCRIPTION AFTER PAYMENT CONFIRM
     =============================================================== */
    public function createSubscriptionAfterPayment(Request $request)
    {
        Log::info('Starting subscription creation', ['request' => $request->all()]);

        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
            'payment_method' => 'required|string',
            'payment_intent_id' => 'required|string',
        ]);

        try {

            $tenancy = $this->getTenantTenancyOrFail($request->tenancy_id);
            $this->ensureRentDeedReady($tenancy);

            Log::info('Loaded tenancy', ['tenancy_id' => $tenancy->id]);

            [$stripeSecret] = $this->ensureStripeConfigured();
            Stripe::setApiKey($stripeSecret);
            $this->ensureStripeBillingProfile($tenancy, auth()->user());

            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);
            $this->validateStripeCurrency($paymentIntent->currency ?? null, 'payment_intent_id');

            if (($paymentIntent->status ?? null) !== 'succeeded') {
                return response()->json([
                    'message' => 'Rent payment is not completed yet.',
                    'reason' => 'Payment intent is not in succeeded state.',
                ], 422);
            }

            if (($paymentIntent->metadata->tenancy_id ?? null) != $tenancy->id) {
                return response()->json([
                    'message' => 'Payment verification failed.',
                    'reason' => 'Payment intent does not belong to this tenancy.',
                ], 422);
            }

            if (($paymentIntent->metadata->type ?? null) !== 'rent_subscription') {
                return response()->json([
                    'message' => 'Payment verification failed.',
                    'reason' => 'Unexpected payment type for subscription creation.',
                ], 422);
            }

            if (! empty($paymentIntent->payment_method) && $paymentIntent->payment_method !== $request->payment_method) {
                return response()->json([
                    'message' => 'Payment verification failed.',
                    'reason' => 'Payment method mismatch for the confirmed payment intent.',
                ], 422);
            }

            $intentAmount = (int) ($paymentIntent->amount_received ?: $paymentIntent->amount ?: 0);
            $expectedAmount = (int) ($paymentIntent->metadata->expected_amount ?? 0);

            if ($expectedAmount > 0 && $intentAmount !== $expectedAmount) {
                throw ValidationException::withMessages([
                    'payment_intent_id' => ['Collected amount does not match the expected rent amount.'],
                ]);
            }

            if ($tenancy->hasLiveSubscription()) {
                $this->syncActiveSubscriptionPrice($tenancy);

                return response()->json(array_merge([
                    'success' => true,
                ], $this->subscriptionStatusPayload($tenancy)));
            }

            $coveredScheduleIds = collect(explode(',', (string) ($paymentIntent->metadata->schedule_ids ?? '')))
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

            $coveredSchedules = RentSchedule::where('tenancy_id', $tenancy->id)
                ->whereIn('id', $coveredScheduleIds)
                ->get();

            if ($coveredScheduleIds->isEmpty() || $coveredSchedules->isEmpty()) {
                throw ValidationException::withMessages([
                    'payment_intent_id' => ['Confirmed payment is missing the rent schedule snapshot required for subscription activation.'],
                ]);
            }

            $cancelAt = $tenancy->end_date
                ? Carbon::parse($tenancy->end_date)->endOfDay()->timestamp
                : null;

            $response = DB::transaction(function () use ($tenancy, $request, $cancelAt, $coveredSchedules) {

                Log::info('Preparing to attach payment method', [
                    'tenancy_id' => $tenancy->id,
                    'payment_method_id' => $request->payment_method,
                ]);

                // Retrieve payment method
                $pm = PaymentMethod::retrieve($request->payment_method);

                Log::info('Payment method retrieved', ['pm_id' => $pm->id, 'attached_to' => $pm->customer]);

                if ($pm->customer && $pm->customer !== $tenancy->stripe_customer_id) {
                    throw ValidationException::withMessages([
                        'payment_method' => ['This payment method belongs to another customer.'],
                    ]);
                }

                // Attach PM if not attached
                if (! $pm->customer) {
                    $pm->attach(['customer' => $tenancy->stripe_customer_id]);
                    Log::info('Payment method attached', ['pm_id' => $pm->id]);
                }

                Customer::update($tenancy->stripe_customer_id, [
                    'invoice_settings' => ['default_payment_method' => $pm->id],
                ]);

                $idempotencyKey = 'tenancy_sub_'.$tenancy->id.'_'.$request->payment_intent_id;
                Log::info('Using idempotency key', ['key' => $idempotencyKey]);
                $billingAnchor = $this->nextBillingAnchor($tenancy, $coveredSchedules);

                $subscription = Subscription::create([
                    'customer' => $tenancy->stripe_customer_id,
                    'items' => [
                        ['price' => $tenancy->stripe_price_id],
                    ],
                    'default_payment_method' => $pm->id,
                    'cancel_at' => $cancelAt,
                    'trial_end' => $billingAnchor->timestamp,
                    'metadata' => [
                        'tenancy_id' => $tenancy->id,
                        'property_id' => $tenancy->property_id,
                        'tenant_id' => $tenancy->tenant_id,
                    ],
                ], [
                    'idempotency_key' => $idempotencyKey,
                ]);

                Log::info('Subscription created successfully', [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                    'current_period_end' => $subscription->current_period_end,
                    'billing_anchor' => $billingAnchor->toDateString(),
                ]);

                $tenancy->stripe_subscription_id = $subscription->id;
                $tenancy->subscription_active = 1;
                $tenancy->subscription_cancel_at = null;
                $tenancy->save();

                Log::info('Tenancy updated with subscription', ['tenancy_id' => $tenancy->id]);

                NotificationService::notifyTenancyStakeholders(
                    $tenancy,
                    'subscription',
                    null,
                    ['event' => 'activated']
                );

                Log::info('Notification sent for subscription', ['tenancy_id' => $tenancy->id]);

                return [
                    'success' => true,
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                    'subscription_active' => 1,
                    'subscription_cancel_at' => null,
                    'current_period_end' => $subscription->current_period_end,
                    'flow' => 'pay_and_subscribe',
                ];
            });

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            Log::critical('Subscription creation failed', [
                'tenancy_id' => $request->tenancy_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Rent payment succeeded, but automatic subscription activation failed.',
                'reason' => $this->stripeUserFacingReason($e),
            ], 500);
        }
    }

    public function overdueRents($tenancyId)
    {
        try {
            $tenancy = $this->getTenantTenancyOrFail($tenancyId);
            $this->ensureRentDeedReady($tenancy);
            $tenancy->loadMissing('property');
            $overdues = RentSchedule::where('tenancy_id', $tenancyId)
                ->where('due_date', '<=', now()->endOfMonth())
                ->whereIn('status', ['pending', 'overdue'])
                ->orderBy('due_date')
                ->get(['id', 'month', 'amount', 'due_date', 'status']);
            $lateFeeSummary = $this->lateFeeSummary($tenancy, $overdues);

            return response()->json([
                'items' => $overdues->map(fn ($schedule) => [
                    'id' => $schedule->id,
                    'month' => $schedule->month,
                    'amount' => $schedule->amount,
                    'due_date' => $schedule->due_date,
                    'status' => $schedule->status,
                    'late_fee_amount' => round((float) collect($lateFeeSummary['items'])->where('rent_schedule_id', $schedule->id)->sum('amount'), 2),
                    'payable_total' => round((float) $schedule->amount + (float) collect($lateFeeSummary['items'])->where('rent_schedule_id', $schedule->id)->sum('amount'), 2),
                ])->values(),
                'base_total' => round((float) $overdues->sum('amount'), 2),
                'late_fee_total' => round((float) $lateFeeSummary['total'], 2),
                'total' => round((float) $overdues->sum('amount') + (float) $lateFeeSummary['total'], 2),
                'late_payment_penalty_policy' => $tenancy->property?->late_payment_penalty,
                'late_fee_policy_active' => ! empty($tenancy->property?->late_payment_penalty),
                'late_fee_amount' => round((float) $lateFeeSummary['total'], 2),
                'late_fee_applied' => (float) $lateFeeSummary['total'] > 0,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            Log::error('Fetch overdue rents failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'We could not load overdue rent for this tenancy. Please try again.',
            ], 500);
        }
    }

    /* ===============================================================
     | PAY OVERDUE RENTS
     =============================================================== */
    public function payOverdues(Request $request)
    {
        $request->validate(['tenancy_id' => 'required|exists:property_tenant,id']);

        try {
            $response = DB::transaction(function () use ($request) {

                $tenancy = $this->getTenantTenancyOrFail($request->tenancy_id);
                $this->ensureRentDeedReady($tenancy);
                $shouldPrepareAutoPay = (int) $tenancy->subscription_active < 1 || empty($tenancy->stripe_subscription_id);

                $overdues = RentSchedule::where('tenancy_id', $tenancy->id)
                    ->where('due_date', '<=', now()->endOfMonth())
                    ->whereIn('status', ['pending', 'overdue'])
                    ->lockForUpdate()
                    ->orderBy('due_date')
                    ->get();

                if ($overdues->isEmpty()) {
                    throw ValidationException::withMessages([
                        'tenancy_id' => ['No due unpaid rent found for this tenancy.'],
                    ]);
                }

                $lateFeeSummary = $this->lateFeeSummary($tenancy, $overdues);
                $amount = (float) $overdues->sum('amount') + (float) $lateFeeSummary['total'];
                [$stripeSecret, , $currency] = $this->ensureStripeConfigured();
                Stripe::setApiKey($stripeSecret);

                if ($shouldPrepareAutoPay) {
                    $this->ensureStripeBillingProfile($tenancy, auth()->user());
                }

                $intentPayload = [
                    'amount' => (int) round($amount * 100),
                    'currency' => $currency,
                    'automatic_payment_methods' => ['enabled' => true],
                    'metadata' => [
                        'tenancy_id' => $tenancy->id,
                        'type' => 'overdue',
                        'schedule_ids' => $this->scheduleIdsMetadata($overdues),
                        'expected_amount' => (string) ((int) round($amount * 100)),
                        'prepare_autopay' => $shouldPrepareAutoPay ? '1' : '0',
                        ...$this->lateFeeMetadata($lateFeeSummary),
                    ],
                ];

                if ($shouldPrepareAutoPay) {
                    $intentPayload['customer'] = $tenancy->stripe_customer_id;
                    $intentPayload['setup_future_usage'] = 'off_session';
                }

                $intent = PaymentIntent::create($intentPayload);

                $this->paymentAttemptService->createPendingPaymentsForSchedules(
                    tenancy: $tenancy,
                    schedules: $overdues,
                    type: 'overdue',
                    paymentIntentId: $intent->id
                );

                if ((float) $lateFeeSummary['total'] > 0) {
                    $this->paymentAttemptService->createPendingPayment(
                        tenancy: $tenancy,
                        type: 'late_fee',
                        amount: (float) $lateFeeSummary['total'],
                        paymentIntentId: $intent->id
                    );
                }

                return ['client_secret' => $intent->client_secret];
            });

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            $tenancy = null;

            if ($request->filled('tenancy_id')) {
                $tenancy = $this->tenantTenancyQuery()->find($request->tenancy_id);
            }

            return $this->paymentErrorResponse(
                message: 'Unable to initiate overdue rent payment.',
                e: $e,
                tenancy: $tenancy,
                type: 'overdue'
            );
        }
    }

    /* ===============================================================
     | CANCEL SUBSCRIPTION
     =============================================================== */
    public function cancelSubscription(PropertyTenant $tenancy)
    {
        $tenancy = $this->tenancyForSubscriptionStakeholderOrFail($tenancy->id);

        if (! $tenancy->stripe_subscription_id) {
            return response()->json(['message' => 'No active subscription'], 422);
        }

        try {
            $cancelAt = $this->currentMonthEndCancellationAt();

            if (
                (int) $tenancy->subscription_active === 3
                && $tenancy->subscription_cancel_at
                && $tenancy->subscription_cancel_at->equalTo($cancelAt)
            ) {
                return response()->json([
                    'message' => 'Subscription cancellation is already scheduled for month end.',
                    'cancel_at' => $this->isoDateTimeOrNull($tenancy->subscription_cancel_at),
                    'status' => 'scheduled_for_cancellation',
                ]);
            }

            $this->stripeSubscriptionService->scheduleCancellation($tenancy->stripe_subscription_id, $cancelAt);

            $tenancy->update([
                'subscription_active' => 3,
                'subscription_cancel_at' => $cancelAt,
            ]);
            $tenancy->refresh();

            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'subscription',
                null,
                [
                    'event' => 'scheduled',
                    'cancel_at' => $cancelAt->toIso8601String(),
                    'amount' => $this->resolveMonthlyRent($tenancy),
                ]
            );

            return response()->json([
                'message' => 'Subscription cancellation scheduled for the end of the current month.',
                'cancel_at' => $this->isoDateTimeOrNull($tenancy->subscription_cancel_at),
                'status' => 'scheduled_for_cancellation',
            ]);
        } catch (\Throwable $e) {
            Log::critical('Cancel subscription failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Subscription cancellation failed.',
                'reason' => $this->stripeUserFacingReason($e),
            ], 500);
        }
    }

    public function getSavedCard($tenancyId)
    {
        try {
            $tenancy = $this->getTenantTenancyOrFail($tenancyId);
            $secret = config('services.stripe.secret');
            if (! $secret) {
                return response()->json(['message' => 'Payments are not configured.', 'card' => null], 503);
            }

            if (! $tenancy->stripe_customer_id) {
                return response()->json(['card' => null]);
            }
            Stripe::setApiKey($secret);
            $customer = Customer::retrieve($tenancy->stripe_customer_id);
            $defaultPm = $customer->invoice_settings->default_payment_method ?? null;
            if (! $defaultPm) {
                return response()->json(['card' => null]);
            }
            $pm = PaymentMethod::retrieve($defaultPm);
            if (($pm->type ?? '') === 'card' && isset($pm->card)) {
                return response()->json([
                    'card' => [
                        'id' => $pm->id,
                        'brand' => $pm->card->brand,
                        'last4' => $pm->card->last4,
                        'exp' => $pm->card->exp_month.'/'.$pm->card->exp_year,
                    ],
                ]);
            }

            return response()->json([
                'card' => [
                    'id' => $pm->id,
                    'brand' => $pm->type ?? 'payment_method',
                    'last4' => null,
                    'exp' => null,
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            Log::error('getSavedCard failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Unable to load saved payment method.',
                'card' => null,
            ], 500);
        }
    }

    /* ===============================================================
     | PAY SECURITY DEPOSIT
     =============================================================== */
    public function paySecurityDeposit(Request $request)
    {
        $request->validate(['tenancy_id' => 'required|exists:property_tenant,id']);

        try {
            $response = DB::transaction(function () use ($request) {

                $tenancy = PropertyTenant::with(['property.owner', 'property.manager', 'tenant'])
                    ->where('id', $request->tenancy_id)
                    ->where('tenant_id', auth()->id())
                    ->firstOrFail();
                $this->ensureRentDeedReady($tenancy);

                [$stripeSecret, , $currency] = $this->ensureStripeConfigured();
                Stripe::setApiKey($stripeSecret);

                if ((float) $tenancy->security_deposit_amount <= 0) {
                    throw ValidationException::withMessages([
                        'tenancy_id' => ['Security deposit is not configured for this tenancy.'],
                    ]);
                }

                if ($tenancy->security_deposit_status === 'paid') {
                    throw ValidationException::withMessages([
                        'tenancy_id' => ['Security deposit has already been paid.'],
                    ]);
                }

                $intent = PaymentIntent::create([
                    'amount' => (int) round($tenancy->security_deposit_amount * 100),
                    'currency' => $currency,
                    'automatic_payment_methods' => ['enabled' => true],
                    'metadata' => [
                        'tenancy_id' => $tenancy->id,
                        'type' => 'security_deposit',
                        'expected_amount' => (string) ((int) round($tenancy->security_deposit_amount * 100)),
                    ],
                ]);

                $this->paymentAttemptService->createPendingPayment(
                    tenancy: $tenancy,
                    type: 'security_deposit',
                    amount: (float) $tenancy->security_deposit_amount,
                    paymentIntentId: $intent->id
                );

                return ['client_secret' => $intent->client_secret];
            });

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Tenancy not found'], 404);
        } catch (\Throwable $e) {
            $tenancy = null;

            if ($request->filled('tenancy_id')) {
                $tenancy = $this->tenantTenancyQuery()->find($request->tenancy_id);
            }

            return $this->paymentErrorResponse(
                message: 'Unable to initiate security deposit payment.',
                e: $e,
                tenancy: $tenancy,
                type: 'security_deposit',
                amount: $tenancy ? (float) $tenancy->security_deposit_amount : null
            );
        }
    }
}
