<?php

namespace App\Providers;

use App\Modules\Chat\Events\ChatCreated;
use App\Modules\Chat\Events\MessageSent;
use App\Modules\Chat\Listeners\SendChatCreatedNotifications;
use App\Modules\Chat\Listeners\SendChatMessageNotifications;
use App\Modules\Maintenance\Events\MaintenanceCommentAdded;
use App\Modules\Maintenance\Events\MaintenanceRequestCreated;
use App\Modules\Maintenance\Events\MaintenanceStatusUpdated;
use App\Modules\Maintenance\Listeners\SendMaintenanceCommentAddedNotifications;
use App\Modules\Maintenance\Listeners\SendMaintenanceRequestCreatedNotifications;
use App\Modules\Maintenance\Listeners\SendMaintenanceStatusUpdatedNotifications;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        ChatCreated::class => [
            SendChatCreatedNotifications::class,
        ],
        MessageSent::class => [
            SendChatMessageNotifications::class,
        ],
        MaintenanceRequestCreated::class => [
            SendMaintenanceRequestCreatedNotifications::class,
        ],
        MaintenanceStatusUpdated::class => [
            SendMaintenanceStatusUpdatedNotifications::class,
        ],
        MaintenanceCommentAdded::class => [
            SendMaintenanceCommentAddedNotifications::class,
        ],
    ];
}
