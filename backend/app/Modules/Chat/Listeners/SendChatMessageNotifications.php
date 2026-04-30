<?php

namespace App\Modules\Chat\Listeners;

use App\Modules\Chat\Events\MessageSent;
use App\Modules\Chat\Services\ChatNotificationService;

class SendChatMessageNotifications
{
    public function __construct(
        private readonly ChatNotificationService $notifications
    ) {
    }

    public function handle(MessageSent $event): void
    {
        $this->notifications->notifyNewMessage($event->message);
    }
}
