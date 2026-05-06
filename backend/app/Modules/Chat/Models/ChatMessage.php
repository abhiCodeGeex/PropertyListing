<?php

namespace App\Modules\Chat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_FILE = 'file';
    public const TYPE_IMAGE = 'image';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_FILE,
        self::TYPE_IMAGE,
    ];

    protected $table = 'messages';

    protected $fillable = [
        'chat_id',
        'sender_id',
        'receiver_id',
        'type',
        'body',
        'meta',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class, 'message_id')->whereNull('deleted_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class, 'message_id')->whereNull('deleted_at')->orderBy('id');
    }
}
