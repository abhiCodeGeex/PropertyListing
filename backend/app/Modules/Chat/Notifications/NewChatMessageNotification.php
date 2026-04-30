<?php

namespace App\Modules\Chat\Notifications;

use App\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;

class NewChatMessageNotification extends BaseChatNotification
{
    public function __construct(
        private readonly Chat $chat,
        private readonly ChatMessage $message,
        private readonly User $sender
    ) {
    }

    protected function payload(object $notifiable): array
    {
        return [
            'subject' => 'New Chat Message',
            'preheader' => "{$this->sender->name} sent you a new chat message.",
            'greeting' => "Hello {$notifiable->name},",
            'intro' => "{$this->sender->name} sent a new message in ".($this->chat->title ?: 'your direct chat').'.',
            'message' => $this->message->body ?: 'Open the chat to review the new attachment.',
            'details' => [
                'Chat' => $this->chat->title ?: 'Direct chat',
                'Sender' => $this->sender->name,
                'Message Type' => ucfirst($this->message->type),
                'Sent At' => optional($this->message->created_at)->toDayDateTimeString() ?? now()->toDayDateTimeString(),
            ],
            'closing' => 'Open the workspace to respond and keep the conversation moving.',
            'action_url' => rtrim((string) config('app.url_frontend'), '/').'/chat/'.$this->chat->id,
            'action_label' => 'Open Chat',
            'severity' => 'primary',
        ];
    }
}
