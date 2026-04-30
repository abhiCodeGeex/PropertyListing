<?php

namespace App\Modules\Maintenance\Events;

use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceComment;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaintenanceCommentAdded implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public MaintenanceRequest $maintenanceRequest,
        public MaintenanceComment $comment,
        public User $actor
    ) {
    }

    public function broadcastOn(): array
    {
        $property = $this->maintenanceRequest->property;
        $userIds = collect([
            $this->maintenanceRequest->tenant_id,
            $property?->user_id,
            $property?->manager_id,
            $this->maintenanceRequest->assigned_to,
        ])->filter()->unique()->values();

        $channels = $userIds
            ->map(fn (int $userId) => new PrivateChannel('user.'.$userId))
            ->all();

        if ($property?->id) {
            $channels[] = new PrivateChannel('property.'.$property->id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'comment.added';
    }

    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->maintenanceRequest->id,
            'status' => $this->maintenanceRequest->status,
            'comment_id' => $this->comment->id,
            'message' => 'Maintenance comment added.',
            'timestamp' => optional($this->comment->created_at)->toIso8601String(),
            'user_id' => $this->actor->id,
            'property_id' => $this->maintenanceRequest->property_id,
        ];
    }
}
