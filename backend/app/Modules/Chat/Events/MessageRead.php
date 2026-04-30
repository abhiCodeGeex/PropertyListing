<?php

namespace App\Modules\Chat\Events;

use App\Models\User;
use App\Modules\Chat\Models\Chat;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Chat $chat,
        public readonly User $reader,
        public readonly int $messageId,
        public readonly string $readAt
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->chat->id);
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chat->id,
            'message_id' => $this->messageId,
            'read_by_id' => $this->reader->id,
            'read_by_name' => $this->reader->name,
            'read_at' => $this->readAt,
        ];
    }
}
