<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function rentSchedule()
    {
        return $this->belongsTo(
            RentSchedule::class,
            'rent_schedule_id',
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

    public function propertyTenant()
    {
        return $this->belongsTo(PropertyTenant::class, 'tenancy_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'primary_payment_id');
    }
}
