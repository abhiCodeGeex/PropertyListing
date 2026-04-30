<?php

namespace App\Modules\Maintenance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceAttachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'maintenance_request_id',
        'maintenance_comment_id',
        'user_id',
        'disk',
        'path',
        'file_name',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(MaintenanceComment::class, 'maintenance_comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
