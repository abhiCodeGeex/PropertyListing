<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class ManualSecurityDepositEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public $tenancy;

    public function __construct($tenancy)
    {
        $this->tenancy = $tenancy;
    }

    public function broadcastOn()
    {
        return collect([
            $this->tenancy->tenant_id,
            $this->tenancy->property?->user_id,
            $this->tenancy->property?->manager_id,
        ])
            ->filter()
            ->unique()
            ->map(fn ($userId) => new PrivateChannel("user.{$userId}"))
            ->values()
            ->all();
    }

    public function broadcastAs()
    {
        return 'manual.security.deposit';
    }
}
