<?php

namespace App\Modules\Maintenance\Notifications;

use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceComment;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Support\Str;

class MaintenanceCommentAddedNotification extends BaseMaintenanceNotification
{
    public function __construct(
        private readonly MaintenanceRequest $maintenanceRequest,
        private readonly MaintenanceComment $comment,
        private readonly User $author
    ) {
    }

    protected function payload(object $notifiable): array
    {
        $propertyName = $this->maintenanceRequest->property?->property_name ?? 'property';

        return [
            'subject' => 'New Maintenance Comment',
            'message' => "{$this->author->name} added a comment to maintenance request #{$this->maintenanceRequest->id}.",
            'preheader' => 'There is new discussion on a maintenance request.',
            'greeting' => "Hello {$notifiable->name},",
            'intro' => 'A new comment was added to a maintenance request you can access.',
            'details' => [
                'Request ID' => '#'.$this->maintenanceRequest->id,
                'Property' => $propertyName,
                'Status' => ucfirst(str_replace('_', ' ', $this->maintenanceRequest->status)),
                'Author' => $this->author->name,
                'Comment' => Str::limit($this->comment->body, 180),
            ],
            'closing' => 'Review the request thread for the full context and any required follow-up.',
            'severity' => 'warning',
        ];
    }
}
