<?php

namespace App\Modules\Maintenance\Policies;

use App\Models\Property;
use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceRequest;

class MaintenanceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['tenant', 'owner', 'property_manager', 'super-admin']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('tenant');
    }

    public function view(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        if ((int) $maintenanceRequest->tenant_id === (int) $user->id) {
            return true;
        }

        if ((int) $maintenanceRequest->assigned_to === (int) $user->id) {
            return true;
        }

        return $this->canManageProperty($user, $maintenanceRequest->property);
    }

    public function comment(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return $this->view($user, $maintenanceRequest);
    }

    public function approve(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return $this->canManageProperty($user, $maintenanceRequest->property);
    }

    public function reject(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return $this->canManageProperty($user, $maintenanceRequest->property);
    }

    public function updateStatus(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return $this->canManageProperty($user, $maintenanceRequest->property);
    }

    public function assign(User $user, MaintenanceRequest $maintenanceRequest): bool
    {
        return $this->canManageProperty($user, $maintenanceRequest->property);
    }

    private function canManageProperty(User $user, ?Property $property): bool
    {
        if (! $property) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        if ($user->hasRole('owner') && (int) $property->user_id === (int) $user->id) {
            return true;
        }

        return $user->hasRole('property_manager') && (int) $property->manager_id === (int) $user->id;
    }
}
