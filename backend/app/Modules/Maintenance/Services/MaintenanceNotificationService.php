<?php

namespace App\Modules\Maintenance\Services;

use App\Events\NotificationCreated;
use App\Models\Notification as NotificationModel;
use App\Models\User;
use App\Modules\Maintenance\Events\MaintenanceCommentAdded;
use App\Modules\Maintenance\Events\MaintenanceRequestCreated;
use App\Modules\Maintenance\Events\MaintenanceStatusUpdated;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Maintenance\Notifications\MaintenanceCommentAddedNotification;
use App\Modules\Maintenance\Notifications\MaintenanceRequestCreatedNotification;
use App\Modules\Maintenance\Notifications\MaintenanceStatusUpdatedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MaintenanceNotificationService
{
    public function notifyRequestCreated(MaintenanceRequestCreated $event): void
    {
        $request = $event->maintenanceRequest;
        $recipients = $this->uniqueUsers([
            $request->property?->owner,
            $request->property?->manager,
        ])->reject(fn (User $user) => (int) $user->id === (int) $request->tenant_id);

        foreach ($recipients as $recipient) {
            $this->createInAppNotification(
                user: $recipient,
                type: 'maintenance_request',
                title: 'Maintenance Request Created',
                message: "{$request->tenant?->name} submitted a maintenance request for {$request->property?->property_name}.",
                maintenanceRequest: $request,
                context: [
                    'event' => 'request_created',
                    'request_id' => $request->id,
                ]
            );

            $recipient->notify(new MaintenanceRequestCreatedNotification($request));
        }
    }

    public function notifyStatusUpdated(MaintenanceStatusUpdated $event): void
    {
        $request = $event->maintenanceRequest;
        $recipientPool = [
            $request->tenant,
        ];

        if ($event->action === 'assigned') {
            $recipientPool[] = $request->assignee;
        }

        $recipients = $this->uniqueUsers($recipientPool)
            ->reject(fn (User $user) => (int) $user->id === (int) $event->actor->id);

        $title = match ($event->action) {
            'approved' => 'Maintenance Request Approved',
            'rejected' => 'Maintenance Request Rejected',
            'assigned' => 'Maintenance Request Assigned',
            default => 'Maintenance Request Updated',
        };

        foreach ($recipients as $recipient) {
            $this->createInAppNotification(
                user: $recipient,
                type: 'maintenance_status',
                title: $title,
                message: $event->messageText ?: "Maintenance request #{$request->id} is now {$request->status}.",
                maintenanceRequest: $request,
                context: [
                    'event' => $event->action,
                    'request_id' => $request->id,
                    'status' => $request->status,
                ]
            );

            $recipient->notify(new MaintenanceStatusUpdatedNotification(
                maintenanceRequest: $request,
                action: $event->action,
                reason: $event->reason,
                message: $event->messageText
            ));
        }
    }

    public function notifyCommentAdded(MaintenanceCommentAdded $event): void
    {
        $request = $event->maintenanceRequest;
        $recipients = $this->uniqueUsers([
            $request->tenant,
            $request->property?->owner,
            $request->property?->manager,
            $request->assignee,
        ])->reject(fn (User $user) => (int) $user->id === (int) $event->actor->id);

        foreach ($recipients as $recipient) {
            $this->createInAppNotification(
                user: $recipient,
                type: 'maintenance_comment',
                title: 'Maintenance Comment Added',
                message: "{$event->actor->name} added a comment to request #{$request->id}.",
                maintenanceRequest: $request,
                context: [
                    'event' => 'comment_added',
                    'request_id' => $request->id,
                    'comment_id' => $event->comment->id,
                ]
            );

            $recipient->notify(new MaintenanceCommentAddedNotification(
                maintenanceRequest: $request,
                comment: $event->comment,
                author: $event->actor
            ));
        }
    }

    private function createInAppNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        MaintenanceRequest $maintenanceRequest,
        array $context = []
    ): void {
        $notification = NotificationModel::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'notifiable_type' => MaintenanceRequest::class,
            'notifiable_id' => $maintenanceRequest->id,
        ]);

        try {
            broadcast(new NotificationCreated(
                notification: $notification->toArray(),
                userId: $user->id,
                context: $context
            ))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Maintenance notification broadcast failed', [
                'user_id' => $user->id,
                'maintenance_request_id' => $maintenanceRequest->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, User|null>  $users
     */
    private function uniqueUsers(array $users): Collection
    {
        return collect($users)
            ->filter()
            ->unique(fn (User $user) => $user->id)
            ->values();
    }
}
