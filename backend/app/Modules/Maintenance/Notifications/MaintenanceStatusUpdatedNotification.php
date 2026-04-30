<?php

namespace App\Modules\Maintenance\Notifications;

use App\Modules\Maintenance\Models\MaintenanceRequest;

class MaintenanceStatusUpdatedNotification extends BaseMaintenanceNotification
{
    public function __construct(
        private readonly MaintenanceRequest $maintenanceRequest,
        private readonly string $action,
        private readonly ?string $reason = null,
        private readonly ?string $message = null,
    ) {
    }

    protected function payload(object $notifiable): array
    {
        $statusLabel = ucfirst(str_replace('_', ' ', $this->maintenanceRequest->status));
        $propertyName = $this->maintenanceRequest->property?->property_name ?? 'property';
        $subject = match ($this->action) {
            'approved' => 'Maintenance Request Approved',
            'rejected' => 'Maintenance Request Rejected',
            'assigned' => 'Maintenance Request Assigned',
            default => 'Maintenance Request Updated',
        };

        return [
            'subject' => $subject,
            'message' => $this->message ?: "Maintenance request #{$this->maintenanceRequest->id} is now {$statusLabel}.",
            'preheader' => "Maintenance request #{$this->maintenanceRequest->id} has been updated.",
            'greeting' => "Hello {$notifiable->name},",
            'intro' => 'A maintenance request in your workspace has a new update.',
            'details' => array_filter([
                'Request ID' => '#'.$this->maintenanceRequest->id,
                'Property' => $propertyName,
                'Status' => $statusLabel,
                'Priority' => ucfirst($this->maintenanceRequest->priority),
                'Assignee' => $this->maintenanceRequest->assignee?->name,
                'Reason' => $this->reason,
            ]),
            'closing' => 'Open the maintenance request details to review the latest activity.',
            'severity' => in_array($this->maintenanceRequest->status, [
                MaintenanceRequest::STATUS_REJECTED,
                MaintenanceRequest::STATUS_CANCELLED,
            ], true) ? 'danger' : 'success',
        ];
    }
}
