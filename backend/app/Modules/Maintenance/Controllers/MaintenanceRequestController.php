<?php

namespace App\Modules\Maintenance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Maintenance\Models\MaintenanceAttachment;
use App\Modules\Maintenance\Models\MaintenanceComment;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Maintenance\Services\MaintenanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaintenanceRequestController extends Controller
{
    public function __construct(
        private readonly MaintenanceRequestService $service
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MaintenanceRequest::class);

        $payload = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'tenancy_id' => 'required|exists:property_tenant,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'category' => 'nullable|string|max:100',
            'priority' => ['required', Rule::in(MaintenanceRequest::PRIORITIES)],
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,txt,webp',
        ]);

        $maintenanceRequest = $this->service->createRequest(
            tenant: $request->user(),
            payload: $payload,
            files: $request->file('attachments', [])
        );

        return response()->json([
            'message' => 'Maintenance request created successfully.',
            'request' => $this->requestPayload($maintenanceRequest, true),
        ], 201);
    }

    public function myRequests(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole('tenant'), 403, 'Unauthorized');

        $requests = $this->service->paginateMyRequests($request->user(), $this->filters($request));
        $requests->through(fn (MaintenanceRequest $item) => $this->requestPayload($item));

        return response()->json($requests);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        $requests = $this->service->paginateWorkspaceRequests($request->user(), $this->filters($request));
        $requests->through(fn (MaintenanceRequest $item) => $this->requestPayload($item));

        return response()->json($requests);
    }

    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        return response()->json([
            'summary' => $this->service->workspaceSummary($request->user()),
        ]);
    }

    public function show(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('view', $maintenanceRequest);

        $loaded = $this->service->getVisibleRequest($request->user(), $maintenanceRequest->id);

        return response()->json([
            'request' => $this->requestPayload($loaded, true),
        ]);
    }

    public function addComment(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('comment', $maintenanceRequest);

        $payload = $request->validate([
            'body' => 'required|string|max:3000',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,txt,webp',
        ]);

        $comment = $this->service->addComment(
            actor: $request->user(),
            maintenanceRequest: $maintenanceRequest,
            payload: $payload,
            files: $request->file('attachments', [])
        );

        return response()->json([
            'message' => 'Comment added successfully.',
            'comment' => $this->commentPayload($comment),
        ], 201);
    }

    public function approve(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('approve', $maintenanceRequest);

        $payload = $request->validate([
            'reason' => 'required|string|max:2000',
            'message' => 'nullable|string|max:2000',
        ]);

        $updated = $this->service->approve($request->user(), $maintenanceRequest, $payload);

        return response()->json([
            'message' => 'Maintenance request approved.',
            'request' => $this->requestPayload($updated, true),
        ]);
    }

    public function reject(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('reject', $maintenanceRequest);

        $payload = $request->validate([
            'reason' => 'required|string|max:2000',
            'message' => 'nullable|string|max:2000',
        ]);

        $updated = $this->service->reject($request->user(), $maintenanceRequest, $payload);

        return response()->json([
            'message' => 'Maintenance request rejected.',
            'request' => $this->requestPayload($updated, true),
        ]);
    }

    public function updateStatus(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('updateStatus', $maintenanceRequest);

        $payload = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    MaintenanceRequest::STATUS_IN_PROGRESS,
                    MaintenanceRequest::STATUS_ON_HOLD,
                    MaintenanceRequest::STATUS_COMPLETED,
                    MaintenanceRequest::STATUS_CANCELLED,
                ]),
            ],
            'reason' => 'nullable|string|max:2000',
            'message' => 'nullable|string|max:2000',
        ]);

        $updated = $this->service->updateStatus($request->user(), $maintenanceRequest, $payload);

        return response()->json([
            'message' => 'Maintenance request status updated.',
            'request' => $this->requestPayload($updated, true),
        ]);
    }

    public function assign(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('assign', $maintenanceRequest);

        $payload = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'message' => 'nullable|string|max:2000',
        ]);

        $updated = $this->service->assign($request->user(), $maintenanceRequest, $payload);

        return response()->json([
            'message' => 'Maintenance request assigned successfully.',
            'request' => $this->requestPayload($updated, true),
        ]);
    }

    public function downloadAttachment(
        Request $request,
        MaintenanceRequest $maintenanceRequest,
        MaintenanceAttachment $attachment
    ) {
        $this->authorize('view', $maintenanceRequest);

        return $this->service->downloadAttachment($maintenanceRequest, $attachment);
    }

    private function filters(Request $request): array
    {
        return [
            'status' => $request->input('status'),
            'priority' => $request->input('priority'),
            'property_id' => $request->input('property_id'),
            'search' => $request->input('search'),
            'sort_by' => $request->input('sort_by', 'last_activity_at'),
            'sort_order' => $request->input('sort_order', 'desc'),
            'per_page' => $request->integer('per_page', 10),
        ];
    }

    private function requestPayload(MaintenanceRequest $maintenanceRequest, bool $detailed = false): array
    {
        $property = $maintenanceRequest->property;

        $payload = [
            'id' => $maintenanceRequest->id,
            'property_id' => $maintenanceRequest->property_id,
            'tenancy_id' => $maintenanceRequest->tenancy_id,
            'tenant_id' => $maintenanceRequest->tenant_id,
            'assigned_to' => $maintenanceRequest->assigned_to,
            'title' => $maintenanceRequest->title,
            'description' => $maintenanceRequest->description,
            'category' => $maintenanceRequest->category,
            'priority' => $maintenanceRequest->priority,
            'status' => $maintenanceRequest->status,
            'attachments_count' => $maintenanceRequest->attachments_count ?? $maintenanceRequest->allAttachments->count(),
            'comments_count' => $maintenanceRequest->comments_count ?? $maintenanceRequest->comments->count(),
            'last_activity_at' => optional($maintenanceRequest->last_activity_at)->toIso8601String(),
            'resolved_at' => optional($maintenanceRequest->resolved_at)->toIso8601String(),
            'created_at' => optional($maintenanceRequest->created_at)->toIso8601String(),
            'updated_at' => optional($maintenanceRequest->updated_at)->toIso8601String(),
            'property' => [
                'id' => $property?->id,
                'property_name' => $property?->property_name,
                'city' => $property?->city,
                'state' => $property?->state,
                'address' => $property?->address,
                'owner' => $property?->owner ? [
                    'id' => $property->owner->id,
                    'name' => $property->owner->name,
                    'email' => $property->owner->email,
                ] : null,
                'manager' => $property?->manager ? [
                    'id' => $property->manager->id,
                    'name' => $property->manager->name,
                    'email' => $property->manager->email,
                ] : null,
            ],
            'tenant' => $maintenanceRequest->tenant ? [
                'id' => $maintenanceRequest->tenant->id,
                'name' => $maintenanceRequest->tenant->name,
                'email' => $maintenanceRequest->tenant->email,
            ] : null,
            'assignee' => $maintenanceRequest->assignee ? [
                'id' => $maintenanceRequest->assignee->id,
                'name' => $maintenanceRequest->assignee->name,
                'email' => $maintenanceRequest->assignee->email,
            ] : null,
        ];

        if (! $detailed) {
            return $payload;
        }

        $payload['attachments'] = $maintenanceRequest->attachments
            ->map(fn (MaintenanceAttachment $attachment) => $this->attachmentPayload($attachment, $maintenanceRequest->id))
            ->values()
            ->all();
        $payload['comments'] = $maintenanceRequest->comments
            ->map(fn (MaintenanceComment $comment) => $this->commentPayload($comment))
            ->values()
            ->all();
        $payload['status_history'] = $maintenanceRequest->statusHistory
            ->map(fn ($history) => [
                'id' => $history->id,
                'action' => $history->action,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'reason' => $history->reason,
                'message' => $history->message,
                'metadata' => $history->metadata,
                'created_at' => optional($history->created_at)->toIso8601String(),
                'user' => $history->user ? [
                    'id' => $history->user->id,
                    'name' => $history->user->name,
                    'email' => $history->user->email,
                ] : null,
            ])
            ->values()
            ->all();

        return $payload;
    }

    private function commentPayload(MaintenanceComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'updated_at' => optional($comment->updated_at)->toIso8601String(),
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
                'email' => $comment->user->email,
            ] : null,
            'attachments' => $comment->attachments
                ->map(fn (MaintenanceAttachment $attachment) => $this->attachmentPayload($attachment, $comment->maintenance_request_id))
                ->values()
                ->all(),
        ];
    }

    private function attachmentPayload(MaintenanceAttachment $attachment, int $requestId): array
    {
        return [
            'id' => $attachment->id,
            'maintenance_comment_id' => $attachment->maintenance_comment_id,
            'original_name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'created_at' => optional($attachment->created_at)->toIso8601String(),
            'download_endpoint' => "/api/v1/maintenance/requests/{$requestId}/attachments/{$attachment->id}",
            'uploaded_by' => $attachment->user ? [
                'id' => $attachment->user->id,
                'name' => $attachment->user->name,
                'email' => $attachment->user->email,
            ] : null,
        ];
    }
}
