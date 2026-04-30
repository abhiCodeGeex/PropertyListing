<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public array $notification;

    public int $userId;

    public array $context;

    public function __construct(array $notification, int $userId, array $context = [])
    {
        $this->notification = $notification;
        $this->userId = $userId;
        $this->context = $context;
    }

    public function broadcastOn(): PrivateChannel
    {
        // Log::info('NotificationCreated broadcastOn fired', [
        //     'userId' => $this->userId,
        //     'channel' => 'user.' . $this->userId
        // ]);
        return new PrivateChannel('user.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'notification' => $this->notification,
            'context' => $this->context,
        ];
    }
}
