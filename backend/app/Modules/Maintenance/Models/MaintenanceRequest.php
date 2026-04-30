<?php

namespace App\Modules\Maintenance\Models;

use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_ASSIGNED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_ASSIGNED => [
            self::STATUS_IN_PROGRESS,
            self::STATUS_ON_HOLD,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_ON_HOLD,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_ON_HOLD => [
            self::STATUS_ASSIGNED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_REJECTED => [],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'property_id',
        'tenancy_id',
        'tenant_id',
        'assigned_to',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'last_activity_at',
        'resolved_at',
        'meta',
    ];

    protected $casts = [
        'last_activity_at' => 'datetime',
        'resolved_at' => 'datetime',
        'meta' => 'array',
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(PropertyTenant::class, 'tenancy_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(MaintenanceComment::class)->latest('created_at')->latest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MaintenanceAttachment::class)
            ->whereNull('maintenance_comment_id')
            ->latest('created_at')
            ->latest('id');
    }

    public function allAttachments(): HasMany
    {
        return $this->hasMany(MaintenanceAttachment::class)->latest('created_at')->latest('id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(MaintenanceStatusHistory::class)->latest('created_at')->latest('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            self::STATUS_REJECTED,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ]);
    }
}
