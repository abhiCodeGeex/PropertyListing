<?php

namespace App\Modules\Maintenance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceStatusHistory extends Model
{
    use SoftDeletes;

    protected $table = 'maintenance_status_history';

    protected $fillable = [
        'maintenance_request_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'reason',
        'message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
