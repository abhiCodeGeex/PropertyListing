<?php

namespace App\Models;

use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PropertyTenant extends Model
{
    protected $table = 'property_tenant';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'manager_id',
        'property_id',
        'start_date',
        'end_date',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'subscription_active',
        'subscription_cancel_at',
        'security_deposit_amount',
        'security_deposit_status',
    ];

    protected $casts = [
        'subscription_cancel_at' => 'datetime',
    ];

    public function property()
    {
        return $this->belongsTo(
            Property::class,
            'property_id',
            'id'
        );
    }

    public function tenant()
    {
        return $this->belongsTo(
            User::class,
            'tenant_id',
            'id'
        );
    }

    public function rentSchedules(): HasMany
    {
        return $this->hasMany(RentSchedule::class, 'tenancy_id');
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'tenancy_id');
    }

    public function rentDeed()
    {
        return $this->hasOne(RentDeed::class, 'property_id', 'property_id')
            ->latestOfMany();
    }

    public function resolveRentDeed(): ?RentDeed
    {
        $query = RentDeed::query()
            ->where('property_id', $this->property_id)
            ->where('tenant_id', $this->tenant_id)
            ->orderByDesc('agreement_date')
            ->orderByDesc('id');

        if ($this->end_date) {
            $datedMatch = (clone $query)
                ->whereDate('agreement_date', '<=', $this->end_date)
                ->first();

            if ($datedMatch) {
                return $datedMatch;
            }
        }

        if ($this->start_date) {
            $datedMatch = (clone $query)
                ->whereDate('agreement_date', '<=', $this->start_date)
                ->first();

            if ($datedMatch) {
                return $datedMatch;
            }
        }

        return $query->first();
    }

    public function resolvedMonthlyRentAmount(): float
    {
        $rentDeed = $this->resolveRentDeed();

        $amount = $rentDeed?->monthly_rent ?? $this->property?->monthly_rent ?? null;

        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw ValidationException::withMessages([
                'tenancy_id' => ['Monthly rent is not configured for this tenancy.'],
            ]);
        }

        return round((float) $amount, 2);
    }

    public function hasStripeSubscription(): bool
    {
        return ! empty($this->stripe_subscription_id);
    }

    public function hasActiveSubscription(): bool
    {
        return (int) $this->subscription_active === 1 && $this->hasStripeSubscription();
    }

    public function hasScheduledSubscriptionCancellation(): bool
    {
        return (int) $this->subscription_active === 3 && $this->hasStripeSubscription();
    }

    public function hasLiveSubscription(): bool
    {
        return $this->hasActiveSubscription() || $this->hasScheduledSubscriptionCancellation();
    }
}
