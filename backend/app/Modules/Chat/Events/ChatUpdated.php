<?php

namespace App\Modules\Chat\Events;

use App\Modules\Chat\Models\Chat;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Chat $chat,
        public readonly string $action,
        public readonly int $actorId,
        public readonly ?int $affectedUserId = null,
        public readonly bool $chatDeleted = false,
        public readonly int $participantCount = 0
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->chat->id);
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chat->id,
            'action' => $this->action,
            'actor_id' => $this->actorId,
            'affected_user_id' => $this->affectedUserId,
            'chat_deleted' => $this->chatDeleted,
            'participant_count' => $this->participantCount,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
