<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentSchedule extends Model
{
    protected $table = 'rent_schedules';

    protected $guarded = [];

    protected $casts = [
        'due_date' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    public function tenancy()
    {
        return $this->belongsTo(
            PropertyTenant::class,
            'tenancy_id',
            'id'
        );
    }
}
