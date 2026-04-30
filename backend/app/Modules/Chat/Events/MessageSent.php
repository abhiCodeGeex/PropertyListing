<?php

namespace App\Modules\Chat\Events;

use App\Modules\Chat\Models\ChatMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChatMessage $message
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('chat.'.$this->message->chat_id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing([
            'sender',
            'attachments',
            'reads.user',
        ]);

        return [
            'chat_id' => $message->chat_id,
            'message_id' => $message->id,
            'sender_id' => $message->sender_id,
            'sender' => [
                'id' => $message->sender?->id,
                'name' => $message->sender?->name,
                'email' => $message->sender?->email,
            ],
            'message' => $message->body,
            'type' => $message->type,
            'timestamp' => optional($message->created_at)->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
            ])->values()->all(),
            'read_by_ids' => $message->reads->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }
}
