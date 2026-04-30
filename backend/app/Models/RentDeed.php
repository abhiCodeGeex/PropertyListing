<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class RentDeed extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'agreement_number',
        'agreement_date',
        'file_path',
        'owner_id',
        'tenant_id',
        'property_id',
        'rent_due_date',
        'maintenance_charges',
        'other_details',
    ];

    protected $appends = ['file_url'];

    protected $casts = [
        'agreement_date' => 'date',
    ];

    public function getFileUrlAttribute()
    {
        return $this->file_path ? Storage::disk('public')->url($this->file_path) : null;
    }

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
