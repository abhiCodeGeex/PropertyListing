<?php

namespace App\Modules\Maintenance\Events;

use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaintenanceRequestCreated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public MaintenanceRequest $maintenanceRequest
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
        return 'request.created';
    }

    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->maintenanceRequest->id,
            'status' => $this->maintenanceRequest->status,
            'message' => 'Maintenance request created.',
            'timestamp' => optional($this->maintenanceRequest->created_at)->toIso8601String(),
            'priority' => $this->maintenanceRequest->priority,
            'property_id' => $this->maintenanceRequest->property_id,
            'tenant_id' => $this->maintenanceRequest->tenant_id,
            'user_id' => $this->maintenanceRequest->tenant_id,
        ];
    }
}
