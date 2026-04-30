<?php

namespace App\Modules\Maintenance\Repositories;

use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceRequestRepository
{
    public function paginateForTenant(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseListQuery()
            ->where('tenant_id', $user->id);

        return $this->paginate($this->applyFilters($query, $filters), $filters);
    }

    public function paginateForWorkspace(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseListQuery();
        $this->applyWorkspaceScope($query, $user);

        return $this->paginate($this->applyFilters($query, $filters), $filters);
    }

    public function findVisibleForUser(User $user, int $id): MaintenanceRequest
    {
        $query = $this->detailQuery()->whereKey($id);

        if (! $user->hasRole('super-admin')) {
            $query->where(function (Builder $builder) use ($user) {
                $builder->where('tenant_id', $user->id)
                    ->orWhere('assigned_to', $user->id)
                    ->orWhereHas('property', function (Builder $propertyQuery) use ($user) {
                        $propertyQuery->where(function (Builder $visibilityQuery) use ($user) {
                            if ($user->hasRole('owner')) {
                                $visibilityQuery->where('user_id', $user->id);
                            }

                            if ($user->hasRole('property_manager')) {
                                $method = $user->hasRole('owner') ? 'orWhere' : 'where';
                                $visibilityQuery->{$method}('manager_id', $user->id);
                            }
                        });
                    });
            });
        }

        return $query->firstOrFail();
    }

    public function workspaceSummary(User $user): array
    {
        $query = MaintenanceRequest::query();
        $this->applyWorkspaceScope($query, $user);

        $summary = $query
            ->selectRaw('COUNT(*) as total')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending',
                [MaintenanceRequest::STATUS_PENDING]
            )
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as in_progress',
                [MaintenanceRequest::STATUS_IN_PROGRESS]
            )
            ->selectRaw(
                'SUM(CASE WHEN priority = ? THEN 1 ELSE 0 END) as urgent',
                [MaintenanceRequest::PRIORITY_URGENT]
            )
            ->first();

        return [
            'total' => (int) ($summary?->total ?? 0),
            'pending' => (int) ($summary?->pending ?? 0),
            'in_progress' => (int) ($summary?->in_progress ?? 0),
            'urgent' => (int) ($summary?->urgent ?? 0),
        ];
    }

    public function detailQuery(): Builder
    {
        return MaintenanceRequest::query()
            ->with([
                'property.owner:id,name,email',
                'property.manager:id,name,email',
                'tenant:id,name,email',
                'assignee:id,name,email',
                'attachments.user:id,name,email',
                'comments.user:id,name,email',
                'comments.attachments.user:id,name,email',
                'statusHistory.user:id,name,email',
            ]);
    }

    public function latestTenantTenancy(int $tenantId, int $propertyId): ?PropertyTenant
    {
        return PropertyTenant::query()
            ->where('tenant_id', $tenantId)
            ->where('property_id', $propertyId)
            ->latest('start_date')
            ->latest('id')
            ->first();
    }

    public function baseListQuery(): Builder
    {
        return MaintenanceRequest::query()
            ->with([
                'property.owner:id,name,email',
                'property.manager:id,name,email',
                'tenant:id,name,email',
                'assignee:id,name,email',
            ])
            ->withCount([
                'comments',
                'allAttachments as attachments_count',
            ]);
    }

    public function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['property_id'])) {
            $query->where('property_id', (int) $filters['property_id']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereHas('property', function (Builder $propertyQuery) use ($search) {
                        $propertyQuery->where('property_name', 'like', "%{$search}%")
                            ->orWhere('city', 'like', "%{$search}%")
                            ->orWhere('state', 'like', "%{$search}%");
                    })
                    ->orWhereHas('tenant', function (Builder $tenantQuery) use ($search) {
                        $tenantQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $sortBy = $filters['sort_by'] ?? 'last_activity_at';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSorts = [
            'created_at',
            'updated_at',
            'last_activity_at',
            'priority',
            'status',
        ];

        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'last_activity_at';
        }

        return $query
            ->orderBy($sortBy, $sortOrder)
            ->orderBy('id', 'desc');
    }

    public function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 50));

        return $query->paginate($perPage)->withQueryString();
    }

    private function applyWorkspaceScope(Builder $query, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        $query->whereHas('property', function (Builder $propertyQuery) use ($user) {
            $propertyQuery->where(function (Builder $builder) use ($user) {
                if ($user->hasRole('owner')) {
                    $builder->where('user_id', $user->id);
                }

                if ($user->hasRole('property_manager')) {
                    $method = $user->hasRole('owner') ? 'orWhere' : 'where';
                    $builder->{$method}('manager_id', $user->id);
                }
            });
        });
    }
}
