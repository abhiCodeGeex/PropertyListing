<?php

namespace App\Modules\Chat\Services;

use App\Events\NotificationCreated;
use App\Models\Notification as NotificationModel;
use App\Models\User;
use App\Modules\Chat\Models\Chat;
use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Notifications\AddedToGroupNotification;
use Illuminate\Support\Collection;

class ChatNotificationService
{
    public function __construct(
        private readonly ChatPresenceService $presence
    ) {
    }

    public function notifyGroupCreated(Chat $chat, User $actor): void
    {
        if ($chat->type !== Chat::TYPE_GROUP) {
            return;
        }

        $chat->loadMissing('participants.user');

        $chat->participants
            ->map(fn ($participant) => $participant->user)
            ->filter()
            ->reject(fn (User $user) => (int) $user->id === (int) $actor->id)
            ->each(function (User $recipient) use ($chat, $actor) {
                if (! $recipient->email || $this->presence->isOnline($recipient->id)) {
                    return;
                }

                $recipient->notify(new AddedToGroupNotification($chat, $actor));
            });
    }

    public function notifyNewMessage(ChatMessage $message): void
    {
        $message->loadMissing([
            'chat.participants.user',
            'sender',
        ]);

        $chat = $message->chat;

        $recipients = $chat->participants
            ->map(fn ($participant) => $participant->user)
            ->filter()
            ->reject(fn (User $user) => (int) $user->id === (int) $message->sender_id);

        $this->notifyOfflineRecipients($chat, $message, $message->sender, $recipients);
    }

    private function notifyOfflineRecipients(
        Chat $chat,
        ChatMessage $message,
        User $sender,
        Collection $recipients
    ): void {
        $recipients->each(function (User $recipient) use ($chat, $message, $sender) {
            $notification = NotificationModel::query()->create([
                'user_id' => $recipient->id,
                'type' => 'chat_message',
                'title' => 'New Chat Message',
                'message' => $this->notificationMessage($chat, $message, $sender),
                'notifiable_type' => Chat::class,
                'notifiable_id' => $chat->id,
            ]);

            try {
                broadcast(new NotificationCreated(
                    notification: $notification->toArray(),
                    userId: (int) $recipient->id,
                    context: [
                        'event' => 'chat_message',
                        'chat_id' => (int) $chat->id,
                        'message_id' => (int) $message->id,
                        'sender_id' => (int) $sender->id,
                    ]
                ))->toOthers();
            } catch (\Throwable) {
                // Chat delivery should not fail because the realtime notification broadcast is unavailable.
            }
        });
    }

    private function notificationMessage(Chat $chat, ChatMessage $message, User $sender): string
    {
        $chatName = $chat->title ?: 'your direct chat';
        $body = trim((string) ($message->body ?? ''));

        if ($body !== '') {
            return "{$sender->name} sent a new message in {$chatName}: {$body}";
        }

        $attachmentLabel = $message->type === ChatMessage::TYPE_IMAGE ? 'an image' : 'an attachment';

        return "{$sender->name} shared {$attachmentLabel} in {$chatName}.";
    }
}
