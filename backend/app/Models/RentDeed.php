<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentDeed extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'agreement_number',
        'agreement_date',
        'owner_id',
        'tenant_id',
        'property_id',
        'size',
        'usage',
        'monthly_rent',
        'payment_mode',
        'due_date',
        'rent_due_date',
        'maintenance_charges',
        'other_details',
    ];

    protected $casts = [
        'monthly_rent' => 'decimal:2',
        'agreement_date' => 'date',
        'due_date' => 'date',
    ];

    // Relationships
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function property()
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }
}
