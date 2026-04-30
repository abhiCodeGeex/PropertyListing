<?php

namespace App\Modules\Maintenance\Listeners;

use App\Modules\Maintenance\Events\MaintenanceCommentAdded;
use App\Modules\Maintenance\Services\MaintenanceNotificationService;

class SendMaintenanceCommentAddedNotifications
{
    public function __construct(
        private readonly MaintenanceNotificationService $notifications
    ) {
    }

    public function handle(MaintenanceCommentAdded $event): void
    {
        $this->notifications->notifyCommentAdded($event);
    }
}
