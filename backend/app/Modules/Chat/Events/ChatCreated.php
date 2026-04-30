<?php

namespace App\Modules\Chat\Events;

use App\Modules\Chat\Models\Chat;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Chat $chat,
        public readonly int $actorId
    ) {
    }

    public function broadcastOn(): array
    {
        $participantIds = $this->chat->participants()
            ->pluck('user_id')
            ->map(fn ($id) => new PrivateChannel('user.'.$id))
            ->all();

        return $participantIds;
    }

    public function broadcastAs(): string
    {
        return 'chat.created';
    }

    public function broadcastWith(): array
    {
        $chat = $this->chat->loadMissing([
            'participants.user.roles',
            'property.owner',
            'property.manager',
            'lastMessage.sender',
            'lastMessage.attachments',
        ]);

        return [
            'chat' => [
                'id' => $chat->id,
                'type' => $chat->type,
                'title' => $chat->title,
                'description' => $chat->description,
                'property_id' => $chat->property_id,
                'last_message_at' => optional($chat->last_message_at)->toIso8601String(),
                'created_at' => optional($chat->created_at)->toIso8601String(),
                'participants' => $chat->participants->map(fn ($participant) => [
                    'id' => $participant->user?->id,
                    'name' => $participant->user?->name,
                    'email' => $participant->user?->email,
                    'roles' => $participant->user?->roles?->pluck('name')->values()->all() ?? [],
                ])->values()->all(),
            ],
            'actor_id' => $this->actorId,
        ];
    }
}
