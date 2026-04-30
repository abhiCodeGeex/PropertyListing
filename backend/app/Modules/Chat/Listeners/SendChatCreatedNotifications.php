<?php

namespace App\Modules\Chat\Listeners;

use App\Models\User;
use App\Modules\Chat\Events\ChatCreated;
use App\Modules\Chat\Services\ChatNotificationService;

class SendChatCreatedNotifications
{
    public function __construct(
        private readonly ChatNotificationService $notifications
    ) {
    }

    public function handle(ChatCreated $event): void
    {
        $actor = User::query()->find($event->actorId);

        if (! $actor) {
            return;
        }

        $this->notifications->notifyGroupCreated($event->chat, $actor);
    }
}
