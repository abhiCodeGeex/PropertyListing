<?php

namespace App\Modules\Maintenance\Notifications;

use App\Modules\Maintenance\Models\MaintenanceRequest;

class MaintenanceRequestCreatedNotification extends BaseMaintenanceNotification
{
    public function __construct(
        private readonly MaintenanceRequest $maintenanceRequest
    ) {
    }

    protected function payload(object $notifiable): array
    {
        $propertyName = $this->maintenanceRequest->property?->property_name ?? 'property';
        $tenantName = $this->maintenanceRequest->tenant?->name ?? 'Tenant';

        return [
            'subject' => 'Maintenance Request Created',
            'message' => "{$tenantName} submitted a maintenance request for {$propertyName}.",
            'preheader' => 'A new maintenance request needs review.',
            'greeting' => "Hello {$notifiable->name},",
            'intro' => 'A new maintenance request has been raised in your workspace and is awaiting review.',
            'details' => [
                'Request ID' => '#'.$this->maintenanceRequest->id,
                'Property' => $propertyName,
                'Tenant' => $tenantName,
                'Priority' => ucfirst($this->maintenanceRequest->priority),
                'Status' => ucfirst(str_replace('_', ' ', $this->maintenanceRequest->status)),
                'Title' => $this->maintenanceRequest->title,
            ],
            'closing' => 'Review the request and update its status from the maintenance dashboard.',
            'severity' => $this->maintenanceRequest->priority === MaintenanceRequest::PRIORITY_URGENT ? 'danger' : 'warning',
        ];
    }
}
