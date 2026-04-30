<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PropertyTenant;
use Illuminate\Support\Collection;

class PaymentAttemptService
{
    public function markPaymentSucceeded(
        PropertyTenant $tenancy,
        string $type,
        float $amount,
        string $paymentIntentId,
        ?string $chargeId = null,
        ?string $eventId = null,
        ?int $rentScheduleId = null,
        array $extra = []
    ): Payment {
        $attributes = array_filter([
            'stripe_payment_intent_id' => $paymentIntentId,
            'type' => $type,
            'rent_schedule_id' => $rentScheduleId,
        ], fn ($value) => $value !== null);

        if (empty($attributes)) {
            $attributes = [
                'stripe_payment_intent_id' => $paymentIntentId,
                'type' => $type,
            ];
        }

        return Payment::updateOrCreate($attributes, array_merge([
            'tenant_id' => $tenancy->tenant_id,
            'property_id' => $tenancy->property_id,
            'tenancy_id' => $tenancy->id,
            'rent_schedule_id' => $rentScheduleId,
            'amount' => $amount,
            'type' => $type,
            'payment_mode' => 'stripe',
            'status' => 'succeeded',
            'failure_reason' => null,
            'stripe_charge_id' => $chargeId,
            'stripe_event_id' => $eventId,
        ], $extra));
    }

    public function markSchedulePaymentsSucceeded(
        PropertyTenant $tenancy,
        Collection $schedules,
        string $type,
        string $paymentIntentId,
        ?string $chargeId = null,
        ?string $eventId = null
    ): Collection {
        return $schedules->map(function ($schedule) use ($tenancy, $type, $paymentIntentId, $chargeId, $eventId) {
            return $this->markPaymentSucceeded(
                tenancy: $tenancy,
                type: $type,
                amount: (float) $schedule->amount,
                paymentIntentId: $paymentIntentId,
                chargeId: $chargeId,
                eventId: $eventId,
                rentScheduleId: $schedule->id
            );
        })->values();
    }

    public function createPendingPayment(
        PropertyTenant $tenancy,
        string $type,
        float $amount,
        ?string $paymentIntentId = null,
        ?int $rentScheduleId = null,
        array $extra = []
    ): Payment {
        $reusablePending = Payment::query()
            ->where('tenant_id', $tenancy->tenant_id)
            ->where('property_id', $tenancy->property_id)
            ->where('tenancy_id', $tenancy->id)
            ->where('type', $type)
            ->where('payment_mode', 'stripe')
            ->where('status', 'pending')
            ->where('rent_schedule_id', $rentScheduleId)
            ->first();

        if ($reusablePending) {
            $reusablePending->fill(array_merge([
                'amount' => $amount,
                'failure_reason' => null,
                'stripe_payment_intent_id' => $paymentIntentId,
            ], $extra));
            $reusablePending->save();

            return $reusablePending;
        }

        $attributes = array_filter([
            'stripe_payment_intent_id' => $paymentIntentId,
            'type' => $type,
            'rent_schedule_id' => $rentScheduleId,
        ], fn ($value) => $value !== null);

        if (empty($attributes)) {
            $attributes = [
                'tenant_id' => $tenancy->tenant_id,
                'property_id' => $tenancy->property_id,
                'type' => $type,
                'payment_mode' => 'stripe',
                'status' => 'pending',
            ];
        }

        return Payment::updateOrCreate($attributes, array_merge([
            'tenant_id' => $tenancy->tenant_id,
            'property_id' => $tenancy->property_id,
            'tenancy_id' => $tenancy->id,
            'rent_schedule_id' => $rentScheduleId,
            'amount' => $amount,
            'type' => $type,
            'payment_mode' => 'stripe',
            'status' => 'pending',
            'failure_reason' => null,
        ], $extra));
    }

    public function createPendingPaymentsForSchedules(
        PropertyTenant $tenancy,
        Collection $schedules,
        string $type,
        string $paymentIntentId
    ): void {
        foreach ($schedules as $schedule) {
            $this->createPendingPayment(
                tenancy: $tenancy,
                type: $type,
                amount: (float) $schedule->amount,
                paymentIntentId: $paymentIntentId,
                rentScheduleId: $schedule->id
            );
        }
    }

    public function markIntentPaymentsFailed(
        PropertyTenant $tenancy,
        ?string $paymentIntentId,
        ?string $chargeId,
        string $reason,
        string $type,
        ?float $amount = null
    ): bool {
        $query = Payment::query()->where(function ($builder) use ($paymentIntentId, $chargeId) {
            if ($paymentIntentId) {
                $builder->orWhere('stripe_payment_intent_id', $paymentIntentId);
            }

            if ($chargeId) {
                $builder->orWhere('stripe_charge_id', $chargeId);
            }
        });

        $updated = 0;

        if ($paymentIntentId || $chargeId) {
            $existing = (clone $query)->get();

            $alreadyHandled = $existing->isNotEmpty() && $existing->every(function (Payment $payment) use ($reason, $paymentIntentId, $chargeId): bool {
                return $payment->status === 'failed'
                    && $payment->failure_reason === $reason
                    && ($paymentIntentId === null || $payment->stripe_payment_intent_id === $paymentIntentId)
                    && ($chargeId === null || $payment->stripe_charge_id === $chargeId);
            });

            if ($alreadyHandled) {
                return false;
            }

            $updated = $query->update([
                'status' => 'failed',
                'failure_reason' => $reason,
                'stripe_payment_intent_id' => $paymentIntentId,
                'stripe_charge_id' => $chargeId,
            ]);
        }

        if ($updated > 0) {
            return true;
        }

        $this->createPendingPayment(
            tenancy: $tenancy,
            type: $type,
            amount: $amount ?? 0,
            paymentIntentId: $paymentIntentId,
            rentScheduleId: null,
            extra: [
                'status' => 'failed',
                'failure_reason' => $reason,
                'stripe_charge_id' => $chargeId,
            ]
        );

        return true;
    }
}
