<?php

namespace App\Modules\Maintenance\Services;

use App\Models\PropertyTenant;
use App\Models\User;
use App\Modules\Maintenance\Events\MaintenanceCommentAdded;
use App\Modules\Maintenance\Events\MaintenanceRequestCreated;
use App\Modules\Maintenance\Events\MaintenanceStatusUpdated;
use App\Modules\Maintenance\Models\MaintenanceAttachment;
use App\Modules\Maintenance\Models\MaintenanceComment;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Maintenance\Repositories\MaintenanceRequestRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceRequestService
{
    public function __construct(
        private readonly MaintenanceRequestRepository $repository
    ) {
    }

    public function paginateMyRequests(User $tenant, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginateForTenant($tenant, $filters);
    }

    public function paginateWorkspaceRequests(User $user, array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginateForWorkspace($user, $filters);
    }

    public function getVisibleRequest(User $user, int $requestId): MaintenanceRequest
    {
        return $this->repository->findVisibleForUser($user, $requestId);
    }

    public function workspaceSummary(User $user): array
    {
        return $this->repository->workspaceSummary($user);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function createRequest(User $tenant, array $payload, array $files = []): MaintenanceRequest
    {
        $tenancy = $this->resolveTenantTenancy(
            tenant: $tenant,
            propertyId: (int) $payload['property_id'],
            tenancyId: (int) $payload['tenancy_id']
        );

        $maintenanceRequest = DB::transaction(function () use ($tenant, $payload, $files, $tenancy) {
            $request = MaintenanceRequest::create([
                'property_id' => $tenancy->property_id,
                'tenancy_id' => $tenancy->id,
                'tenant_id' => $tenant->id,
                'title' => $this->sanitizeLine($payload['title'], 'title'),
                'description' => $this->sanitizeMultiline($payload['description'], 'description'),
                'category' => $this->nullableLine($payload['category'] ?? null),
                'priority' => strtolower((string) ($payload['priority'] ?? MaintenanceRequest::PRIORITY_MEDIUM)),
                'status' => MaintenanceRequest::STATUS_PENDING,
                'last_activity_at' => now(),
                'meta' => [
                    'created_via' => 'api',
                ],
            ]);

            $this->storeAttachments($request, $tenant, $files);
            $this->logHistory(
                maintenanceRequest: $request,
                actor: $tenant,
                action: 'created',
                fromStatus: null,
                toStatus: MaintenanceRequest::STATUS_PENDING,
                reason: null,
                message: 'Maintenance request created.',
                metadata: [
                    'attachment_count' => count($files),
                ]
            );

            return $this->repository->detailQuery()->whereKey($request->id)->firstOrFail();
        });

        event(new MaintenanceRequestCreated($maintenanceRequest));

        return $maintenanceRequest;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function addComment(User $actor, MaintenanceRequest $maintenanceRequest, array $payload, array $files = []): MaintenanceComment
    {
        $comment = DB::transaction(function () use ($actor, $maintenanceRequest, $payload, $files) {
            $comment = $maintenanceRequest->comments()->create([
                'user_id' => $actor->id,
                'body' => $this->sanitizeMultiline($payload['body'], 'body'),
            ]);

            $this->storeAttachments($maintenanceRequest, $actor, $files, $comment);

            $maintenanceRequest->forceFill([
                'last_activity_at' => now(),
            ])->save();

            $this->logHistory(
                maintenanceRequest: $maintenanceRequest,
                actor: $actor,
                action: 'comment_added',
                fromStatus: $maintenanceRequest->status,
                toStatus: $maintenanceRequest->status,
                reason: null,
                message: 'Maintenance comment added.',
                metadata: [
                    'comment_id' => $comment->id,
                    'attachment_count' => count($files),
                ]
            );

            return $comment->load([
                'user:id,name,email',
                'attachments.user:id,name,email',
            ]);
        });

        $freshRequest = $this->repository->detailQuery()->whereKey($maintenanceRequest->id)->firstOrFail();
        event(new MaintenanceCommentAdded($freshRequest, $comment, $actor));

        return $comment;
    }

    public function approve(User $actor, MaintenanceRequest $maintenanceRequest, array $payload): MaintenanceRequest
    {
        return $this->transition(
            actor: $actor,
            maintenanceRequest: $maintenanceRequest,
            toStatus: MaintenanceRequest::STATUS_APPROVED,
            action: 'approved',
            reason: $this->sanitizeMultiline($payload['reason'], 'reason'),
            message: $this->nullableMultiline($payload['message'] ?? null) ?: 'Maintenance request approved.'
        );
    }

    public function reject(User $actor, MaintenanceRequest $maintenanceRequest, array $payload): MaintenanceRequest
    {
        return $this->transition(
            actor: $actor,
            maintenanceRequest: $maintenanceRequest,
            toStatus: MaintenanceRequest::STATUS_REJECTED,
            action: 'rejected',
            reason: $this->sanitizeMultiline($payload['reason'], 'reason'),
            message: $this->nullableMultiline($payload['message'] ?? null) ?: 'Maintenance request rejected.'
        );
    }

    public function updateStatus(User $actor, MaintenanceRequest $maintenanceRequest, array $payload): MaintenanceRequest
    {
        return $this->transition(
            actor: $actor,
            maintenanceRequest: $maintenanceRequest,
            toStatus: strtolower((string) $payload['status']),
            action: 'status_updated',
            reason: $this->nullableMultiline($payload['reason'] ?? null),
            message: $this->nullableMultiline($payload['message'] ?? null) ?: 'Maintenance request updated.'
        );
    }

    public function assign(User $actor, MaintenanceRequest $maintenanceRequest, array $payload): MaintenanceRequest
    {
        $assignee = User::query()->findOrFail((int) $payload['assigned_to']);
        $property = $maintenanceRequest->property;

        $allowedAssigneeIds = collect([
            $property?->user_id,
            $property?->manager_id,
            $actor->hasRole('super-admin') ? $actor->id : null,
        ])->filter()->map(fn ($id) => (int) $id);

        if (! $allowedAssigneeIds->contains((int) $assignee->id) && ! $assignee->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'assigned_to' => ['The selected assignee is not allowed for this property.'],
            ]);
        }

        $fromStatus = $maintenanceRequest->status;
        $toStatus = $fromStatus === MaintenanceRequest::STATUS_APPROVED
            ? MaintenanceRequest::STATUS_ASSIGNED
            : $fromStatus;

        if (! in_array($fromStatus, [
            MaintenanceRequest::STATUS_APPROVED,
            MaintenanceRequest::STATUS_ASSIGNED,
            MaintenanceRequest::STATUS_IN_PROGRESS,
            MaintenanceRequest::STATUS_ON_HOLD,
        ], true)) {
            throw ValidationException::withMessages([
                'assigned_to' => ['This maintenance request cannot be assigned in its current status.'],
            ]);
        }

        $updatedRequest = DB::transaction(function () use ($assignee, $maintenanceRequest, $actor, $fromStatus, $toStatus, $payload) {
            if ($fromStatus === MaintenanceRequest::STATUS_APPROVED && ! MaintenanceRequest::canTransition($fromStatus, $toStatus)) {
                throw ValidationException::withMessages([
                    'assigned_to' => ['This maintenance request cannot move into an assigned state.'],
                ]);
            }

            $maintenanceRequest->forceFill([
                'assigned_to' => $assignee->id,
                'status' => $toStatus,
                'last_activity_at' => now(),
                'resolved_at' => null,
            ])->save();

            $message = $this->nullableMultiline($payload['message'] ?? null)
                ?: "Maintenance request assigned to {$assignee->name}.";

            $this->logHistory(
                maintenanceRequest: $maintenanceRequest,
                actor: $actor,
                action: 'assigned',
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                reason: null,
                message: $message,
                metadata: [
                    'assigned_to' => $assignee->id,
                    'assignee_name' => $assignee->name,
                ]
            );

            return $this->repository->detailQuery()->whereKey($maintenanceRequest->id)->firstOrFail();
        });

        event(new MaintenanceStatusUpdated(
            maintenanceRequest: $updatedRequest,
            previousStatus: $fromStatus,
            status: $updatedRequest->status,
            actor: $actor,
            action: 'assigned',
            reason: null,
            messageText: "Maintenance request assigned to {$assignee->name}."
        ));

        return $updatedRequest;
    }

    public function downloadAttachment(
        MaintenanceRequest $maintenanceRequest,
        MaintenanceAttachment $attachment
    ): StreamedResponse {
        if ((int) $attachment->maintenance_request_id !== (int) $maintenanceRequest->id) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime_type,
            ]
        );
    }

    private function transition(
        User $actor,
        MaintenanceRequest $maintenanceRequest,
        string $toStatus,
        string $action,
        ?string $reason = null,
        ?string $message = null
    ): MaintenanceRequest {
        $fromStatus = $maintenanceRequest->status;

        if (! MaintenanceRequest::canTransition($fromStatus, $toStatus)) {
            throw ValidationException::withMessages([
                'status' => ["Maintenance request cannot transition from {$fromStatus} to {$toStatus}."],
            ]);
        }

        $updatedRequest = DB::transaction(function () use ($maintenanceRequest, $toStatus, $actor, $action, $fromStatus, $reason, $message) {
            $maintenanceRequest->forceFill([
                'status' => $toStatus,
                'last_activity_at' => now(),
                'resolved_at' => in_array($toStatus, [
                    MaintenanceRequest::STATUS_COMPLETED,
                    MaintenanceRequest::STATUS_CANCELLED,
                    MaintenanceRequest::STATUS_REJECTED,
                ], true) ? now() : null,
            ])->save();

            $this->logHistory(
                maintenanceRequest: $maintenanceRequest,
                actor: $actor,
                action: $action,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                reason: $reason,
                message: $message ?: 'Maintenance request updated.',
                metadata: null
            );

            return $this->repository->detailQuery()->whereKey($maintenanceRequest->id)->firstOrFail();
        });

        event(new MaintenanceStatusUpdated(
            maintenanceRequest: $updatedRequest,
            previousStatus: $fromStatus,
            status: $updatedRequest->status,
            actor: $actor,
            action: $action,
            reason: $reason,
            messageText: $message
        ));

        return $updatedRequest;
    }

    private function resolveTenantTenancy(User $tenant, int $propertyId, int $tenancyId): PropertyTenant
    {
        $tenancy = PropertyTenant::query()
            ->with(['property.owner', 'property.manager', 'tenant'])
            ->whereKey($tenancyId)
            ->where('property_id', $propertyId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $tenancy) {
            throw ValidationException::withMessages([
                'tenancy_id' => ['The selected tenancy is not assigned to the authenticated tenant.'],
            ]);
        }

        return $tenancy;
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function storeAttachments(
        MaintenanceRequest $maintenanceRequest,
        User $actor,
        array $files,
        ?MaintenanceComment $comment = null
    ): void {
        foreach ($files as $file) {
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $generatedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
            $path = $file->storeAs(
                'maintenance/'.$maintenanceRequest->id,
                $generatedName,
                'local'
            );

            $maintenanceRequest->allAttachments()->create([
                'maintenance_comment_id' => $comment?->id,
                'user_id' => $actor->id,
                'disk' => 'local',
                'path' => $path,
                'file_name' => $generatedName,
                'original_name' => $this->sanitizeOriginalFilename($file->getClientOriginalName()),
                'mime_type' => (string) $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
            ]);
        }
    }

    private function logHistory(
        MaintenanceRequest $maintenanceRequest,
        User $actor,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $reason,
        ?string $message,
        ?array $metadata
    ): void {
        $maintenanceRequest->statusHistory()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    private function sanitizeOriginalFilename(string $name): string
    {
        return trim(str_replace(['\\', '/'], '-', $name));
    }

    private function sanitizeLine(string $value, string $field): string
    {
        $sanitized = trim(strip_tags($value));

        if ($sanitized === '') {
            throw ValidationException::withMessages([
                $field => ['This field is required.'],
            ]);
        }

        return preg_replace('/\s+/', ' ', $sanitized) ?: $sanitized;
    }

    private function nullableLine(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = trim(strip_tags($value));

        return $sanitized === '' ? null : preg_replace('/\s+/', ' ', $sanitized);
    }

    private function sanitizeMultiline(string $value, string $field): string
    {
        $sanitized = trim(strip_tags($value));

        if ($sanitized === '') {
            throw ValidationException::withMessages([
                $field => ['This field is required.'],
            ]);
        }

        return preg_replace("/\r\n?/", "\n", $sanitized) ?: $sanitized;
    }

    private function nullableMultiline(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $sanitized = trim(strip_tags($value));

        if ($sanitized === '') {
            return null;
        }

        return preg_replace("/\r\n?/", "\n", $sanitized) ?: $sanitized;
    }
}
