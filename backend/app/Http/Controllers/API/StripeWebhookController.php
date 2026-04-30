<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use App\Services\InvoiceIssueService;
use App\Services\LateFeePolicyService;
use App\Services\NotificationService;
use App\Services\PaymentAttemptService;
use App\Services\StripePayoutService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentAttemptService $paymentAttemptService,
        private readonly InvoiceIssueService $invoiceIssueService,
        private readonly StripePayoutService $stripePayoutService
    ) {}

    private function scheduleIdsFromMetadata($metadata): array
    {
        $raw = (string) ($metadata->schedule_ids ?? '');

        return collect(explode(',', $raw))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
    }

    private function expectedAmountFromMetadata($metadata): ?int
    {
        $value = $metadata->expected_amount ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function lateFeeReferenceDateFromMetadata($metadata): Carbon
    {
        $value = $metadata->late_fee_reference_date ?? null;

        return $value ? Carbon::parse($value)->startOfDay() : now()->startOfDay();
    }

    private function lateFeeSummaryFromMetadata(PropertyTenant $tenancy, $schedules, $metadata): array
    {
        $referenceDate = $this->lateFeeReferenceDateFromMetadata($metadata);
        $calculated = $this->lateFeeSummaryForTenancySchedules($tenancy, $schedules, $referenceDate);
        $lateFeeTotal = is_numeric($metadata->late_fee_total ?? null)
            ? round(((int) $metadata->late_fee_total) / 100, 2)
            : round((float) ($calculated['total'] ?? 0), 2);
        $lateFeeScheduleIds = collect(explode(',', (string) ($metadata->late_fee_schedule_ids ?? '')))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();
        $lateFeeUnitAmount = is_numeric($metadata->late_fee_unit_amount ?? null)
            ? round(((int) $metadata->late_fee_unit_amount) / 100, 2)
            : null;
        $policyText = (string) ($metadata->late_fee_policy_text ?? ($calculated['policy_text'] ?? ''));

        $items = $lateFeeScheduleIds->map(function (int $scheduleId) use ($schedules, $lateFeeUnitAmount, $policyText) {
            $schedule = collect($schedules)->firstWhere('id', $scheduleId);

            if (! $schedule) {
                return null;
            }

            $dueDate = Carbon::parse($schedule->due_date)->startOfDay();
            $labelMonth = $schedule->month
                ? Carbon::parse($schedule->month)->format('F Y')
                : $dueDate->format('F Y');

            return [
                'rent_schedule_id' => $schedule->id,
                'month' => $labelMonth,
                'due_date' => $dueDate->toDateString(),
                'amount' => $lateFeeUnitAmount ?? 0.0,
                'policy_text' => $policyText,
            ];
        })->filter()->values()->all();

        if (! empty($items)) {
            return [
                'policy_text' => $policyText,
                'type' => 'fixed',
                'grace_days' => (int) ($calculated['grace_days'] ?? 0),
                'base_total' => round((float) collect($schedules)->sum('amount'), 2),
                'total' => $lateFeeTotal,
                'applied_count' => count($items),
                'items' => $items,
            ];
        }

        return array_merge($calculated, [
            'policy_text' => $policyText,
            'total' => $lateFeeTotal,
        ]);
    }

    private function lateFeeSummaryForTenancySchedules(PropertyTenant $tenancy, $schedules, Carbon $referenceDate): array
    {
        $tenancy->loadMissing('property');

        return app(LateFeePolicyService::class)->summarize(
            collect($schedules),
            $tenancy->property?->late_payment_penalty,
            $referenceDate
        );
    }

    private function paymentIntentIdFromStripeObject(object $obj): ?string
    {
        $objectId = $obj->id ?? null;

        if (is_string($objectId) && str_starts_with($objectId, 'pi_')) {
            return $objectId;
        }

        $paymentIntentId = $obj->payment_intent ?? null;

        return is_string($paymentIntentId) && $paymentIntentId !== '' ? $paymentIntentId : null;
    }

    private function chargeIdFromStripeObject(object $obj): ?string
    {
        $objectId = $obj->id ?? null;

        if (is_string($objectId) && str_starts_with($objectId, 'ch_')) {
            return $objectId;
        }

        if (isset($obj->charge) && is_string($obj->charge) && $obj->charge !== '') {
            return $obj->charge;
        }

        $latestCharge = $obj->latest_charge ?? null;

        if (is_string($latestCharge) && $latestCharge !== '') {
            return $latestCharge;
        }

        if (isset($obj->charges->data[0]->id) && is_string($obj->charges->data[0]->id)) {
            return $obj->charges->data[0]->id;
        }

        if (isset($obj->last_payment_error->charge) && is_string($obj->last_payment_error->charge)) {
            return $obj->last_payment_error->charge;
        }

        return null;
    }

    public function handle(Request $request)
    {
        $webhookSecret = config('services.stripe.webhook_secret');
        if (! is_string($webhookSecret) || $webhookSecret === '') {
            Log::critical('Stripe webhook secret is not configured');

            return response()->json(['error' => 'Webhook misconfigured'], 503);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                $webhookSecret
            );

            Log::info('Stripe Event Received', [
                'event_id' => $event->id,
                'event_type' => $event->type,
            ]);

            return match ($event->type) {

                'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($event),

                'invoice.payment_failed',
                'payment_intent.payment_failed',
                'payment_intent.requires_payment_method',
                'charge.failed',
                'invoice.finalization_failed' => $this->handleAnyPaymentFailed($event),

                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),

                'customer.subscription.created' => $this->handleSubscriptionCreated($event),
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($event),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),

                default => response()->json(['ok' => true]),
            };
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            Log::warning('Stripe webhook signature or payload rejected', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid webhook request'], 400);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /* ================= HELPERS ================= */

    private function findTenancyBySubscription($subscriptionId)
    {
        return PropertyTenant::with('property')
            ->where('stripe_subscription_id', $subscriptionId)
            ->first();
    }

    private function findTenancyById($id)
    {
        return PropertyTenant::with('property')->find($id);
    }

    /**
     * Resolve tenancy from subscription webhook payloads when metadata.tenancy_id
     * is missing or stale (Stripe copies static metadata, but subscription id is authoritative).
     */
    private function resolveTenancyForStripeSubscription(object $sub): ?PropertyTenant
    {
        $tenancyId = $sub->metadata->tenancy_id ?? null;
        if ($tenancyId) {
            $tenancy = $this->findTenancyById($tenancyId);
            if ($tenancy) {
                return $tenancy;
            }
        }

        $stripeSubId = $sub->id ?? null;

        if (is_string($stripeSubId) && str_starts_with($stripeSubId, 'sub_')) {
            return $this->findTenancyBySubscription($stripeSubId);
        }

        return null;
    }

    /* ================= RENT SUCCESS ================= */

    private function handleInvoicePaymentSucceeded($event)
    {
        $invoice = $event->data->object;
        $amountPaid = ($invoice->amount_paid ?? 0) / 100;
        $line = $invoice->lines->data[0] ?? null;
        $periodStart = is_object($line) && isset($line->period->start) ? (int) $line->period->start : null;

        if (! $periodStart) {
            Log::warning('invoice.payment_succeeded missing invoice line period', [
                'invoice_id' => $invoice->id ?? null,
            ]);

            return response()->json(['ok' => true]);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $customer = $stripe->customers->retrieve($invoice->customer);
        } catch (\Throwable $e) {
            Log::error('Stripe customer retrieve failed for invoice.payment_succeeded', [
                'invoice_id' => $invoice->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['ok' => true]);
        }

        $tenancyId = $customer->metadata->tenancy_id ?? null;

        if (! $tenancyId) {
            return response()->json(['ok' => true]);
        }

        $tenancy = $this->findTenancyById($tenancyId);
        if (! $tenancy) {
            return response()->json(['ok' => true]);
        }

        $startOfMonth = Carbon::createFromTimestamp($periodStart)
            ->startOfMonth()
            ->toDateString();

        $endOfMonth = Carbon::createFromTimestamp($periodStart)
            ->endOfMonth()
            ->toDateString();

        $rent = RentSchedule::where('tenancy_id', $tenancy->id)
            ->whereBetween('month', [$startOfMonth, $endOfMonth])
            ->first();

        if (! $rent) {
            return response()->json(['ok' => true]);
        }

        $payment = null;

        DB::transaction(function () use ($rent, $tenancy, $invoice, $event, $amountPaid, &$payment) {
            if ($amountPaid > 0) {
                $payment = Payment::updateOrCreate(
                    ['stripe_invoice_id' => $invoice->id],
                    [
                        'tenant_id' => $tenancy->tenant_id,
                        'rent_schedule_id' => $rent->id,
                        'property_id' => $tenancy->property_id,
                        'tenancy_id' => $tenancy->id,
                        'amount' => $invoice->amount_paid / 100,
                        'status' => 'succeeded',
                        'type' => 'rent_deposit',
                        'payment_mode' => 'stripe',
                        'failure_reason' => null,
                        'stripe_event_id' => $event->id,
                    ]
                );
            }

            $rent->status = 'paid';
            $rent->save();
        });

        if ($amountPaid > 0) {
            $this->stripePayoutService->distributeStripePayment(
                tenancy: $tenancy,
                grossAmountInCents: (int) ($invoice->amount_paid ?? 0),
                sourceType: 'invoice',
                sourceId: (string) ($invoice->id ?? ''),
                paymentType: 'rent_deposit',
                currency: (string) ($invoice->currency ?? 'inr'),
                sourceTransactionId: $this->chargeIdFromStripeObject($invoice)
            );

            $tenancy->setRelation('rentSchedule', collect([$rent]));
            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'rent_deposit',
                null,
                [
                    'event' => 'paid',
                    'payment_type' => 'rent_deposit',
                    'amount' => round((float) $amountPaid, 2),
                    'due_date' => $rent->due_date,
                ]
            );

            if ($payment) {
                $this->invoiceIssueService->issueTenantInvoice(
                    tenancy: $tenancy,
                    type: 'rent',
                    sourceKey: 'rent:stripe-invoice:'.$invoice->id,
                    context: [
                        'primary_payment_id' => $payment->id,
                        'stripe_invoice_id' => $invoice->id,
                        'stripe_payment_intent_id' => $invoice->payment_intent ?? null,
                        'rent_schedules' => collect([$rent]),
                        'paid_at' => now(),
                        'due_date' => $rent->due_date,
                    ]
                );
            }
        }

        return response()->json(['ok' => true]);
    }

    /* ================= PAYMENT FAILURE ================= */
    private function handleAnyPaymentFailed($event)
    {
        Log::info('Stripe Payment Failed Webhook Received', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);

        $obj = $event->data->object;

        Log::info('Stripe Object Payload', [
            'object_id' => $obj->id ?? null,
            'object_type' => $obj->object ?? null,
            'amount' => $obj->amount ?? null,
            'currency' => $obj->currency ?? null,
            'metadata' => $obj->metadata ?? null,
            'subscription' => $obj->subscription ?? null,
        ]);

        // Failure reason (Stripe object shapes differ: Invoice, PaymentIntent, Charge, etc.)
        $lastPayErr = isset($obj->last_payment_error) && is_object($obj->last_payment_error)
            ? $obj->last_payment_error
            : null;
        $reason =
            (is_object($lastPayErr) ? ($lastPayErr->message ?? null) : null)
            ?? ($obj->failure_message ?? null)
            ?? (isset($obj->charges->data[0]->failure_message) ? $obj->charges->data[0]->failure_message : null)
            ?? 'Payment failed';

        Log::error('Stripe Payment Failure Reason', [
            'payment_intent' => $obj->id ?? null,
            'reason' => $reason,
        ]);

        // Find tenancy
        $tenancy = null;

        if (! empty($obj->metadata->tenancy_id)) {
            Log::info('Tenancy ID found in metadata', [
                'tenancy_id' => $obj->metadata->tenancy_id,
            ]);

            $tenancy = $this->findTenancyById($obj->metadata->tenancy_id);
        } elseif (! empty($obj->subscription)) {

            Log::info('Tenancy lookup by subscription', [
                'subscription' => $obj->subscription,
            ]);

            $tenancy = $this->findTenancyBySubscription($obj->subscription);
        }

        if (! $tenancy) {
            Log::warning('Tenancy NOT FOUND', [
                'metadata' => $obj->metadata ?? null,
                'subscription' => $obj->subscription ?? null,
            ]);

            return response()->json(['ok' => true]);
        }

        Log::info('Tenancy Found', [
            'tenancy_id' => $tenancy->id,
            'tenant_id' => $tenancy->tenant_id,
            'property_id' => $tenancy->property_id,
        ]);

        // RentSchedule update (only for invoices)
        $invoicePeriodStart = isset($obj->lines->data[0]->period->start)
            ? (int) $obj->lines->data[0]->period->start
            : null;

        if ($invoicePeriodStart) {

            $month = date('Y-m-01', $invoicePeriodStart);

            Log::info('Updating Rent Schedule to overdue', [
                'tenancy_id' => $tenancy->id,
                'month' => $month,
            ]);

            RentSchedule::where('tenancy_id', $tenancy->id)
                ->where('month', $month)
                ->update(['status' => 'overdue']);
        } else {
            Log::info('No invoice lines found (PaymentIntent event)');
        }

        $paymentIntentId = $this->paymentIntentIdFromStripeObject($obj);
        $chargeId = $this->chargeIdFromStripeObject($obj);

        $type = match ($obj->metadata->type ?? null) {
            'security_deposit' => 'security_deposit',
            'overdue' => 'overdue',
            default => 'rent_deposit',
        };

        $shouldNotify = $this->paymentAttemptService->markIntentPaymentsFailed(
            tenancy: $tenancy,
            paymentIntentId: $paymentIntentId,
            chargeId: $chargeId,
            reason: $reason,
            type: $type,
            amount: ($obj->amount ?? 0) / 100
        );

        Log::info('Payment Record Stored', [
            'payment_intent_id' => $paymentIntentId,
            'charge_id' => $chargeId,
            'amount' => ($obj->amount ?? 0) / 100,
            'status' => 'failed',
            'type' => $type,
        ]);

        if (! $shouldNotify) {
            Log::info('Duplicate Stripe failure event ignored for notifications', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'payment_intent_id' => $paymentIntentId,
                'charge_id' => $chargeId,
                'tenancy_id' => $tenancy->id,
            ]);

            return response()->json(['ok' => true]);
        }

        // Notify users
        try {
            $users = NotificationService::stakeholdersForTenancy($tenancy);

            Log::info('Notifying Users', [
                'user_ids' => $users->map(fn (array $stakeholder) => $stakeholder['user']->id)->all(),
            ]);

            NotificationService::notifyStakeholders(
                stakeholders: $users,
                type: 'payment_failed',
                model: $tenancy,
                reason: $reason,
                context: [
                    'event' => 'failed',
                    'payment_type' => $type,
                    'amount' => round((float) (($obj->amount ?? 0) / 100), 2),
                    'late_fee_amount' => round((float) (($obj->metadata->late_fee_total ?? 0) / 100), 2),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Notification Failed', [
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Stripe payment_failed webhook completed successfully');

        return response()->json(['ok' => true]);
    }

    /* ================= PAYMENT INTENT ================= */

    private function handlePaymentIntentSucceeded($event)
    {
        $intent = $event->data->object;
        $type = $intent->metadata->type ?? null;

        Log::info('Stripe payment_intent.succeeded received', [
            'event_id' => $event->id,
            'intent_id' => $intent->id,
            'type' => $type,
        ]);

        return match ($type) {
            'overdue' => $this->handleOverduePayment($intent, $event),
            'rent_subscription' => $this->handleRentSubscription($intent, $event),
            'security_deposit' => $this->handleSecurityDeposit($intent, $event),
            default => response()->json(['ok' => true]),
        };
    }

    private function handleOverduePayment($intent, $event)
    {
        Log::info('Handling overdue payment', [
            'intent_id' => $intent->id,
            'tenancy_id' => $intent->metadata->tenancy_id ?? null,
        ]);

        $tenancy = $this->findTenancyById($intent->metadata->tenancy_id);

        if (! $tenancy) {
            Log::warning('Tenancy not found for overdue payment', [
                'intent_id' => $intent->id,
                'tenancy_id' => $intent->metadata->tenancy_id ?? null,
            ]);

            return response()->json(['ok' => true]);
        }

        $scheduleIds = $this->scheduleIdsFromMetadata($intent->metadata);

        if (empty($scheduleIds)) {
            Log::critical('Overdue payment intent missing schedule snapshot', [
                'intent_id' => $intent->id,
                'tenancy_id' => $tenancy->id,
            ]);

            return response()->json(['ok' => true]);
        }

        $overdues = RentSchedule::where('tenancy_id', $tenancy->id)
            ->whereIn('id', $scheduleIds)
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->get();

        $lateFeeSummary = $this->lateFeeSummaryFromMetadata($tenancy, $overdues, $intent->metadata);
        $expectedAmount = $this->expectedAmountFromMetadata($intent->metadata);
        $receivedAmount = (int) ($intent->amount_received ?: $intent->amount ?: 0);

        if ($expectedAmount !== null && $receivedAmount !== $expectedAmount) {
            Log::critical('Overdue payment amount mismatch', [
                'intent_id' => $intent->id,
                'tenancy_id' => $tenancy->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $receivedAmount,
            ]);

            return response()->json(['ok' => true]);
        }

        Log::info('Overdue rent schedules found', [
            'tenancy_id' => $tenancy->id,
            'count' => $overdues->count(),
        ]);

        $primaryPayment = null;
        $summary = null;
        $chargeId = $this->chargeIdFromStripeObject($intent);

        DB::transaction(function () use ($overdues, $intent, $tenancy, $event, $chargeId, &$primaryPayment, &$summary, $lateFeeSummary) {
            Log::info('Starting overdue payment DB transaction', [
                'intent_id' => $intent->id,
            ]);

            $payments = $this->paymentAttemptService->markSchedulePaymentsSucceeded(
                tenancy: $tenancy,
                schedules: $overdues,
                type: 'overdue',
                paymentIntentId: $intent->id,
                chargeId: $chargeId,
                eventId: $event->id
            );

            foreach ($overdues as $index => $rent) {
                Log::info('Processing overdue rent schedule', [
                    'rent_schedule_id' => $rent->id,
                    'amount' => $rent->amount,
                    'due_date' => $rent->due_date->toDateString(),
                ]);

                $primaryPayment ??= $payments->get($index);

                $rent->status = 'paid';
                $rent->save();
            }

            if ((float) $lateFeeSummary['total'] > 0) {
                $this->paymentAttemptService->markPaymentSucceeded(
                    tenancy: $tenancy,
                    type: 'late_fee',
                    amount: (float) $lateFeeSummary['total'],
                    paymentIntentId: $intent->id,
                    chargeId: $chargeId,
                    eventId: $event->id
                );
            }

            $summary = collect([
                'base_total' => round((float) $overdues->sum('amount'), 2),
                'late_fee_total' => round((float) $lateFeeSummary['total'], 2),
                'total_amount' => round((float) $overdues->sum('amount') + (float) $lateFeeSummary['total'], 2),
                'count' => $overdues->count(),
                'latest_due' => $overdues->max('due_date'),
                'months' => $overdues->map(fn ($r) => [
                    'id' => $r->id,
                    'month' => $r->due_date->format('F Y'),
                    'amount' => $r->amount,
                    'due_date' => $r->due_date->toDateString(),
                    'late_fee_amount' => round((float) collect($lateFeeSummary['items'])->where('rent_schedule_id', $r->id)->sum('amount'), 2),
                ])->values(),
                'late_fee_items' => collect($lateFeeSummary['items'])->values(),
            ]);

            $tenancy->setRelation('overdueSummary', $summary);

            Log::info('Overdue summary built and attached', [
                'tenancy_id' => $tenancy->id,
                'total' => $summary['total_amount'],
                'rent_count' => $summary['count'],
                'latest_due' => $summary['latest_due'],
            ]);
        });

        Log::info('Sending overdue paid notifications', [
            'tenancy_id' => $tenancy->id,
        ]);

        NotificationService::notifyTenancyStakeholders(
            $tenancy,
            'overdue',
            null,
            [
                'event' => 'paid',
                'payment_type' => 'overdue',
                'amount' => round((float) $summary['total_amount'], 2),
                'base_amount' => round((float) $summary['base_total'], 2),
                'late_fee_amount' => round((float) $summary['late_fee_total'], 2),
                'due_date' => $summary['latest_due'] ?? null,
            ]
        );

        $this->stripePayoutService->distributeStripePayment(
            tenancy: $tenancy,
            grossAmountInCents: $receivedAmount,
            sourceType: 'payment_intent',
            sourceId: (string) $intent->id,
            paymentType: 'overdue',
            currency: (string) ($intent->currency ?? 'inr'),
            sourceTransactionId: $chargeId
        );

        if ($primaryPayment && $summary) {
            $this->invoiceIssueService->issueTenantInvoice(
                tenancy: $tenancy,
                type: 'overdue',
                sourceKey: 'overdue:intent:'.$intent->id,
                context: [
                    'primary_payment_id' => $primaryPayment->id,
                    'stripe_payment_intent_id' => $intent->id,
                    'overdue_summary' => $summary->all(),
                    'paid_at' => now(),
                    'due_date' => $summary['latest_due'] ?? null,
                ]
            );
        }

        Log::info('Overdue payment handled successfully', [
            'intent_id' => $intent->id,
            'tenancy_id' => $tenancy->id,
        ]);

        return response()->json(['ok' => true]);
    }

    private function handleRentSubscription($intent, $event)
    {
        Log::info('Handling rent subscription payment', [
            'intent_id' => $intent->id,
            'tenancy_id' => $intent->metadata->tenancy_id ?? null,
        ]);

        $tenancy = $this->findTenancyById($intent->metadata->tenancy_id);

        if (! $tenancy) {
            Log::warning('Tenancy not found for rent subscription payment', [
                'intent_id' => $intent->id,
                'tenancy_id' => $intent->metadata->tenancy_id ?? null,
            ]);

            return response()->json(['ok' => true]);
        }
        $scheduleIds = $this->scheduleIdsFromMetadata($intent->metadata);

        if (empty($scheduleIds)) {
            Log::critical('Rent payment intent missing schedule snapshot', [
                'intent_id' => $intent->id,
                'tenancy_id' => $tenancy->id,
            ]);

            return response()->json(['ok' => true]);
        }

        $overdues = RentSchedule::where('tenancy_id', $tenancy->id)
            ->whereIn('id', $scheduleIds)
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->get();

        $lateFeeSummary = $this->lateFeeSummaryFromMetadata($tenancy, $overdues, $intent->metadata);
        $expectedAmount = $this->expectedAmountFromMetadata($intent->metadata);
        $receivedAmount = (int) ($intent->amount_received ?: $intent->amount ?: 0);

        if ($expectedAmount !== null && $receivedAmount !== $expectedAmount) {
            Log::critical('Rent subscription payment amount mismatch', [
                'intent_id' => $intent->id,
                'tenancy_id' => $tenancy->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $receivedAmount,
                'schedule_ids' => $scheduleIds,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($overdues->isEmpty()) {
            Log::warning('No matching rent schedules found for successful payment intent', [
                'intent_id' => $intent->id,
                'tenancy_id' => $tenancy->id,
                'schedule_ids' => $scheduleIds,
            ]);

            return response()->json(['ok' => true]);
        }

        $primaryPayment = null;
        $chargeId = $this->chargeIdFromStripeObject($intent);

        DB::transaction(function () use ($overdues, $intent, $tenancy, $event, $chargeId, &$primaryPayment, $lateFeeSummary) {
            Log::info('Starting rent payment DB transaction', [
                'intent_id' => $intent->id,
            ]);

            $payments = $this->paymentAttemptService->markSchedulePaymentsSucceeded(
                tenancy: $tenancy,
                schedules: $overdues,
                type: 'rent_deposit',
                paymentIntentId: $intent->id,
                chargeId: $chargeId,
                eventId: $event->id
            );

            foreach ($overdues as $index => $rent) {
                Log::info('Processing rent schedule', [
                    'rent_schedule_id' => $rent->id,
                    'amount' => $rent->amount,
                    'due_date' => $rent->due_date->toDateString(),
                ]);

                $primaryPayment ??= $payments->get($index);

                $rent->status = 'paid';
                $rent->save();
            }

            if ((float) $lateFeeSummary['total'] > 0) {
                $this->paymentAttemptService->markPaymentSucceeded(
                    tenancy: $tenancy,
                    type: 'late_fee',
                    amount: (float) $lateFeeSummary['total'],
                    paymentIntentId: $intent->id,
                    chargeId: $chargeId,
                    eventId: $event->id
                );
            }

            $tenancy->setRelation('rentSchedule', $overdues);

            Log::info('Rent schedules settled and attached', [
                'tenancy_id' => $tenancy->id,
            ]);
        });

        Log::info('Sending rent paid notifications', [
            'tenancy_id' => $tenancy->id,
        ]);

        NotificationService::notifyTenancyStakeholders(
            $tenancy,
            'rent_deposit',
            null,
            [
                'event' => 'paid',
                'payment_type' => 'rent_deposit',
                'amount' => round((float) $overdues->sum('amount') + (float) $lateFeeSummary['total'], 2),
                'base_amount' => round((float) $overdues->sum('amount'), 2),
                'late_fee_amount' => round((float) $lateFeeSummary['total'], 2),
                'due_date' => $overdues->max('due_date'),
            ]
        );

        $this->stripePayoutService->distributeStripePayment(
            tenancy: $tenancy,
            grossAmountInCents: $receivedAmount,
            sourceType: 'payment_intent',
            sourceId: (string) $intent->id,
            paymentType: 'rent_deposit',
            currency: (string) ($intent->currency ?? 'inr'),
            sourceTransactionId: $chargeId
        );

        if ($primaryPayment) {
            $this->invoiceIssueService->issueTenantInvoice(
                tenancy: $tenancy,
                type: 'rent',
                sourceKey: 'rent:intent:'.$intent->id,
                context: [
                    'primary_payment_id' => $primaryPayment->id,
                    'stripe_payment_intent_id' => $intent->id,
                    'rent_schedules' => $overdues,
                    'late_fee_summary' => $lateFeeSummary,
                    'paid_at' => now(),
                    'due_date' => $overdues->max('due_date'),
                ]
            );
        }

        Log::info('Rent subscription payment handled successfully', [
            'intent_id' => $intent->id,
            'tenancy_id' => $tenancy->id,
        ]);

        return response()->json(['ok' => true]);
    }

    private function handleSecurityDeposit($intent, $event)
    {
        $tenancy = $this->findTenancyById($intent->metadata->tenancy_id);
        if (! $tenancy) {
            return response()->json(['ok' => true]);
        }

        $expectedAmount = $this->expectedAmountFromMetadata($intent->metadata);
        $receivedAmount = (int) ($intent->amount_received ?: $intent->amount ?: 0);

        if ($expectedAmount !== null && $receivedAmount !== $expectedAmount) {
            Log::critical('Security deposit payment amount mismatch', [
                'intent_id' => $intent->id,
                'tenancy_id' => $tenancy->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $receivedAmount,
            ]);

            return response()->json(['ok' => true]);
        }

        $payment = $this->paymentAttemptService->markPaymentSucceeded(
            tenancy: $tenancy,
            type: 'security_deposit',
            amount: $receivedAmount / 100,
            paymentIntentId: $intent->id,
            chargeId: $this->chargeIdFromStripeObject($intent),
            eventId: $event->id
        );

        $tenancy->security_deposit_status = 'paid';
        $tenancy->save();

        NotificationService::notifyTenancyStakeholders(
            $tenancy,
            'security_deposit',
            null,
            [
                'event' => 'paid',
                'payment_type' => 'security_deposit',
                'amount' => round((float) ($receivedAmount / 100), 2),
            ]
        );

        $this->stripePayoutService->distributeStripePayment(
            tenancy: $tenancy,
            grossAmountInCents: $receivedAmount,
            sourceType: 'payment_intent',
            sourceId: (string) $intent->id,
            paymentType: 'security_deposit',
            currency: (string) ($intent->currency ?? 'inr'),
            sourceTransactionId: $this->chargeIdFromStripeObject($intent)
        );

        $this->invoiceIssueService->issueTenantInvoice(
            tenancy: $tenancy,
            type: 'security_deposit',
            sourceKey: 'deposit:intent:'.$intent->id,
            context: [
                'primary_payment_id' => $payment->id,
                'stripe_payment_intent_id' => $intent->id,
                'paid_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /* ================= SUBSCRIPTIONS ================= */

    private function handleSubscriptionCreated($event)
    {
        $sub = $event->data->object;

        $tenancy = $this->resolveTenancyForStripeSubscription($sub);
        if (! $tenancy) {
            return response()->json(['ok' => true]);
        }

        $tenancy->stripe_subscription_id = $sub->id;
        $tenancy->subscription_active = 1;
        $tenancy->subscription_cancel_at = null;
        $tenancy->save();
        NotificationService::notifyTenancyStakeholders(
            $tenancy,
            'subscription',
            null,
            [
                'event' => 'activated',
                'amount' => round((float) ($tenancy->property?->monthly_rent ?? 0), 2),
            ]
        );

        return response()->json(['ok' => true]);
    }

    private function handleSubscriptionUpdated($event)
    {
        $sub = $event->data->object;
        $tenancy = $this->resolveTenancyForStripeSubscription($sub);
        if (! $tenancy) {
            return response()->json(['ok' => true]);
        }

        if (! empty($sub->cancel_at) && (int) $sub->cancel_at > now()->timestamp) {
            $tenancy->subscription_active = 3;
            $tenancy->subscription_cancel_at = Carbon::createFromTimestamp($sub->cancel_at);
            $tenancy->save();

            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'subscription',
                null,
                [
                    'event' => 'scheduled',
                    'cancel_at' => $tenancy->subscription_cancel_at?->toIso8601String(),
                    'amount' => round((float) ($tenancy->property?->monthly_rent ?? 0), 2),
                ]
            );

            return response()->json(['ok' => true]);
        }

        if ($sub->status !== 'past_due') {
            return response()->json(['ok' => true]);
        }

        NotificationService::notifyTenancyStakeholders(
            $tenancy,
            'subscription',
            null,
            [
                'event' => 'past_due',
                'amount' => round((float) ($tenancy->property?->monthly_rent ?? 0), 2),
            ]
        );

        return response()->json(['ok' => true]);
    }

    private function handleSubscriptionDeleted($event)
    {
        $sub = $event->data->object;
        $tenancy = $this->resolveTenancyForStripeSubscription($sub);
        if (! $tenancy) {
            return response()->json(['ok' => true]);
        }

        $tenancy->stripe_subscription_id = null;
        $tenancy->subscription_active = 2;
        $tenancy->subscription_cancel_at = null;
        $tenancy->save();

        NotificationService::notifyTenancyStakeholders(
            $tenancy,
            'subscription',
            null,
            [
                'event' => 'cancelled',
                'amount' => round((float) ($tenancy->property?->monthly_rent ?? 0), 2),
            ]
        );

        return response()->json(['ok' => true]);
    }
}
