<?php

namespace App\Modules\Chat\Notifications;

use App\Models\User;
use App\Modules\Chat\Models\Chat;

class AddedToGroupNotification extends BaseChatNotification
{
    public function __construct(
        private readonly Chat $chat,
        private readonly User $actor
    ) {
    }

    protected function payload(object $notifiable): array
    {
        return [
            'subject' => 'Added To Chat Group',
            'preheader' => "{$this->actor->name} added you to a chat group.",
            'greeting' => "Hello {$notifiable->name},",
            'intro' => "{$this->actor->name} added you to the group \"{$this->chat->title}\".",
            'message' => 'You have been added to a new chat group in the property workspace.',
            'details' => [
                'Group' => $this->chat->title ?: 'Untitled group',
                'Added By' => $this->actor->name,
                'Created At' => optional($this->chat->created_at)->toDayDateTimeString() ?? now()->toDayDateTimeString(),
            ],
            'closing' => 'Open the chat to review the participants and recent activity.',
            'action_url' => rtrim((string) config('app.url_frontend'), '/').'/chat/'.$this->chat->id,
            'action_label' => 'Open Group',
            'severity' => 'primary',
        ];
    }
}
