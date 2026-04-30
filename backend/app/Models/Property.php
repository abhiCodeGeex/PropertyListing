<?php

namespace App\Models;

use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'manager_id',
        'property_name',
        'property_type',
        'state',
        'city',
        'address',
        'monthly_rent',
        'payment_mode',
        'owner_commission_percent',
        'manager_commission_percent',
        'security_amount',
        'refund_terms',
        'agreement_duration',
        'maintenance_responsibilities',
        'termination_clause',
        'late_payment_penalty',
        'electricity_bill_paid_by',
    ];

    protected $casts = [
        'monthly_rent' => 'decimal:2',
        'owner_commission_percent' => 'decimal:2',
        'manager_commission_percent' => 'decimal:2',
        'security_amount' => 'decimal:2',
    ];

    /** 🔹 Relationships */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tenants()
    {
        return $this->belongsToMany(User::class, 'property_tenant', 'property_id', 'tenant_id')
            ->withPivot('start_date', 'end_date')
            ->withTimestamps();
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function latestRentDeed()
    {
        return $this->hasOne(RentDeed::class, 'property_id')->latestOfMany();
    }

    public function rentDeeds()
    {
        return $this->hasMany(RentDeed::class);
    }

    public function maintenanceRequests()
    {
        return $this->hasMany(MaintenanceRequest::class);
    }
}
