<?php

namespace App\Modules\Maintenance\Listeners;

use App\Modules\Maintenance\Events\MaintenanceRequestCreated;
use App\Modules\Maintenance\Services\MaintenanceNotificationService;

class SendMaintenanceRequestCreatedNotifications
{
    public function __construct(
        private readonly MaintenanceNotificationService $notifications
    ) {
    }

    public function handle(MaintenanceRequestCreated $event): void
    {
        $this->notifications->notifyRequestCreated($event);
    }
}
