<?php

namespace App\Modules\Maintenance\Listeners;

use App\Modules\Maintenance\Events\MaintenanceStatusUpdated;
use App\Modules\Maintenance\Services\MaintenanceNotificationService;

class SendMaintenanceStatusUpdatedNotifications
{
    public function __construct(
        private readonly MaintenanceNotificationService $notifications
    ) {
    }

    public function handle(MaintenanceStatusUpdated $event): void
    {
        $this->notifications->notifyStatusUpdated($event);
    }
}
