<?php

namespace App\Modules\Chat\Events;

use App\Models\User;
use App\Modules\Chat\Models\Chat;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Chat $chat,
        public readonly User $user,
        public readonly bool $typing = true
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->chat->id);
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chat->id,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'typing' => $this->typing,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
