<?php

namespace App\Modules\Chat\Models;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Chat extends Model
{
    use SoftDeletes;

    public const TYPE_PRIVATE = 'private';
    public const TYPE_GROUP = 'group';

    protected $fillable = [
        'type',
        'private_key',
        'created_by',
        'property_id',
        'title',
        'description',
        'last_message_id',
        'last_message_at',
        'meta',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'meta' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class)->whereNull('deleted_at')->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->whereNull('deleted_at')->latest('id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    public function isPrivate(): bool
    {
        return $this->type === self::TYPE_PRIVATE;
    }
}
