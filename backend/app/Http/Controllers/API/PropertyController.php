<?php

namespace App\Http\Controllers\API;

use App\Events\ManualRentDepositEvent;
use App\Events\ManualSecurityDepositEvent;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\PropertyMedia;
use App\Models\RentSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use App\Services\InvoiceIssueService;
use App\Services\LateFeePolicyService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PropertyController extends Controller
{
    private function isoDateTimeOrNull(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function authorizePropertyAccess(Property $property, bool $allowManager = true): void
    {
        $user = auth()->user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('owner') && (int) $property->user_id === (int) $user->id) {
            return;
        }

        if ($allowManager && $user->hasRole('property_manager') && (int) $property->manager_id === (int) $user->id) {
            return;
        }

        abort(403, 'Unauthorized');
    }

    private function authorizePropertyAssignment(Property $property): void
    {
        $this->authorizePropertyAccess($property, allowManager: false);
    }

    /**
     * Properties visible in owner/manager/super-admin workspace: super-admin sees all;
     * otherwise owned OR managed (not AND — users with both roles must see the union).
     */
    private function applyWorkspacePropertyScope(Builder $query, User $user): void
    {
        if ($user->hasRole('super-admin')) {
            return;
        }

        $query->where(function (Builder $q) use ($user) {
            if ($user->hasRole('owner') && $user->hasRole('property_manager')) {
                $q->where('user_id', $user->id)
                    ->orWhere('manager_id', $user->id);

                return;
            }

            if ($user->hasRole('owner')) {
                $q->where('user_id', $user->id);

                return;
            }

            if ($user->hasRole('property_manager')) {
                $q->where('manager_id', $user->id);
            }
        });
    }

    private function propertyPayload(Request $request, ?Property $property = null): array
    {
        return [
            'user_id' => $property ? $property->user_id : auth()->id(),
            'property_name' => trim((string) $request->input('propertyName', $property?->property_name ?? '')),
            'property_type' => $request->input('propertyType', $property?->property_type),
            'furnishing_type' => $request->input('furnishingType', $property?->furnishing_type),
            'state' => trim((string) $request->input('state', $property?->state ?? '')),
            'city' => trim((string) $request->input('city', $property?->city ?? '')),
            'address' => trim((string) $request->input('address', $property?->address ?? '')),
            'monthly_rent' => $request->input('monthlyRent', $property?->monthly_rent),
            'payment_mode' => $request->input('paymentMode', $property?->payment_mode),
            'security_amount' => $request->input('securityAmount', $property?->security_amount ?? 0),
            'refund_terms' => $this->nullableTrimmed($request->input('refundTerms', $property?->refund_terms)),
            'agreement_duration' => $this->nullableTrimmed($request->input('agreementDuration', $property?->agreement_duration)),
            'maintenance_responsibilities' => $this->nullableTrimmed($request->input('maintenanceResponsibilities', $property?->maintenance_responsibilities)),
            'termination_clause' => $this->nullableTrimmed($request->input('terminationClause', $property?->termination_clause)),
            'late_payment_penalty' => $this->nullableTrimmed($request->input('latePaymentPenalty', $property?->late_payment_penalty)),
            'electricity_bill_paid_by' => $request->input('electricityBillPaidBy', $property?->electricity_bill_paid_by),
        ];
    }

    private function propertyRules(): array
    {
        return [
            'property_name' => 'required|string|max:255',
            'property_type' => 'required|in:Residential,Commercial',
            'furnishing_type' => 'required|in:Unfurnished,Semi-Furnished,Fully-Furnished',
            'state' => 'required|string|max:100',
            'city' => 'required|string|max:100',
            'address' => 'required|string|min:10',
            'monthly_rent' => 'required|numeric|min:0',
            'payment_mode' => 'required|in:UPI,Cash,Credit/Debit Cards',
            'security_amount' => 'required|numeric|min:0',
            'refund_terms' => 'nullable|string',
            'agreement_duration' => 'nullable|string|max:255',
            'maintenance_responsibilities' => 'nullable|string',
            'termination_clause' => 'nullable|string',
            'late_payment_penalty' => 'nullable|string|max:255',
            'electricity_bill_paid_by' => 'required|in:owner,tenant',
        ];
    }

    private function propertyAttributes(): array
    {
        return [
            'property_name' => 'property name',
            'property_type' => 'property type',
            'furnishing_type' => 'furnishing type',
            'monthly_rent' => 'monthly rent',
            'payment_mode' => 'payment mode',
            'security_amount' => 'security amount',
            'refund_terms' => 'refund terms',
            'agreement_duration' => 'agreement duration',
            'maintenance_responsibilities' => 'maintenance responsibilities',
            'termination_clause' => 'termination clause',
            'late_payment_penalty' => 'late payment penalty',
            'electricity_bill_paid_by' => 'electricity bill paid by',
        ];
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }



    private function shouldHaveMedia(array $payload): bool
    {
        return in_array($payload['furnishing_type'] ?? null, ['Semi-Furnished', 'Fully-Furnished'], true);
    }

    private function syncPropertyMedia(Request $request, Property $property): void
    {
        if ($request->has('deleted_media') && is_array($request->deleted_media)) {
            $mediaToDelete = PropertyMedia::whereIn('id', $request->deleted_media)
                ->where('property_id', $property->id)
                ->get();

            foreach ($mediaToDelete as $media) {
                if ($media->file_path) {
                    Storage::disk('public')->delete($media->file_path);
                }
                $media->delete();
            }
        }

        if (! $request->hasFile('media')) {
            return;
        }

        foreach ((array) $request->file('media') as $file) {
            if (! $file) {
                continue;
            }

            $path = $file->store('property-media', 'public');
            $mime = (string) $file->getMimeType();
            $category = str_starts_with($mime, 'image/') ? 'image' : 'document';

            PropertyMedia::create([
                'property_id' => $property->id,
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'file_type' => $category,
                'mime_type' => $mime,
                'uploaded_by' => auth()->id(),
            ]);
        }
    }
    /** List properties */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Property::with([
            'owner:id,name,email',
            'manager:id,name,email',
            'tenants:id,name,email',
            'media',
        ])->latest();

        /**
         * ROLE BASED ACCESS
         */
        if ($user->hasRole('super-admin')) {
            // full access
        } elseif ($user->hasRole('owner')) {
            $query->where('properties.user_id', $user->id);
        } elseif ($user->hasRole('property_manager')) {
            $query->where('properties.manager_id', $user->id);
        } else {
            abort(403, 'Unauthorized');
        }

        /**
         * SEARCH FILTER
         */
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('property_name', 'LIKE', "%{$search}%")
                    ->orWhere('property_type', 'LIKE', "%{$search}%")
                    ->orWhere('city', 'LIKE', "%{$search}%")
                    ->orWhere('state', 'LIKE', "%{$search}%");
            });
        }

        /**
         * FILTER BY ROLE TYPE
         */
        if ($request->filled('filter_role')) {
            match ($request->filter_role) {
                'owner' => $query->whereNotNull('properties.user_id'),
                'manager' => $query->whereNotNull('properties.manager_id'),
                'tenant' => $query->whereHas('tenants'),
                default => null
            };
        }

        /**
         * FILTER BY USER ID
         */
        if ($request->filled('filter_user_id')) {
            $uid = $request->filter_user_id;

            $query->where(function ($q) use ($uid) {
                $q->where('properties.user_id', $uid)
                    ->orWhere('properties.manager_id', $uid)
                    ->orWhereHas('tenants', function ($t) use ($uid) {
                        $t->where('users.id', $uid); // ✅ FIX
                    });
            });
        }

        /**
         * SORTING
         */
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        $allowedSorts = ['property_name', 'monthly_rent', 'city', 'created_at'];

        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy("properties.$sortBy", $sortOrder);
        }

        /**
         * PAGINATION
         */
        $perPage = $request->get('per_page', 10);
        $properties = $query->paginate($perPage);

        return response()->json($properties);
    }

    /** Store property */
    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasAnyRole(['owner', 'super-admin']), 403, 'Unauthorized');

        $data = $this->propertyPayload($request);
        if (auth()->user()->hasRole('super-admin') && $request->filled('ownerId')) {
            $data['user_id'] = (int) $request->input('ownerId');
        }
        $validator = Validator::make(array_merge($data, ['media' => $request->file('media')]), array_merge($this->propertyRules(), ['media.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:10240']), [], $this->propertyAttributes());

        $validator->after(function ($validator) use ($data, $request) {
            if ($this->shouldHaveMedia($data) && ! $request->hasFile('media')) {
                $validator->errors()->add('media', 'Media upload is recommended for semi/fully furnished properties.');
            }
        });


        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $property = Property::create($data);
        $this->syncPropertyMedia($request, $property);

        return response()->json([
            'message' => 'Property created successfully!',
            'property' => $property->load('owner'),
        ], 201);
    }

    /** Show property */
    public function show(Property $property)
    {
        $this->authorizePropertyAccess($property);

        return response()->json($property->load('owner'));
    }

    /** Update property */
    public function update(Request $request, Property $property)
    {
        $this->authorizePropertyAccess($property);

        $data = $this->propertyPayload($request, $property);
        $validator = Validator::make(array_merge($data, ['media' => $request->file('media')]), array_merge($this->propertyRules(), ['media.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:10240']), [], $this->propertyAttributes());

        $validator->after(function ($validator) use ($data, $request, $property) {
            $hasExistingMedia = $property->media()->exists();
            if ($this->shouldHaveMedia($data) && ! $request->hasFile('media') && ! $hasExistingMedia) {
                $validator->errors()->add('media', 'Media upload is recommended for semi/fully furnished properties.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only update provided fields
        foreach ($data as $key => $value) {
            $property->$key = $value;
        }
        $property->save();

        // Sync property media (handle file uploads and deletions)
        $this->syncPropertyMedia($request, $property);

        return response()->json([
            'message' => 'Property updated successfully!',
            'property' => $property->load(['owner', 'media']),
        ]);
    }

    /** Soft delete */
    public function destroy(Property $property)
    {
        $this->authorizePropertyAccess($property);

        $property->delete();

        return response()->json(['message' => 'Property deleted successfully!']);
    }

    /** Restore soft-deleted property */
    public function restore($id)
    {
        $property = Property::withTrashed()->findOrFail($id);
        $property->restore();

        return response()->json([
            'message' => 'Property restored successfully!',
            'property' => $property,
        ]);
    }

    /** Assign tenants to property */
    public function assign(Request $request, Property $property)
    {
        $this->authorizePropertyAccess($property);

        $validated = $request->validate([
            'tenants' => 'required|array',
            'tenants.*.id' => 'required|exists:users,id',
            'tenants.*.start_date' => 'required|date',
            'tenants.*.end_date' => 'nullable|date|after:tenants.*.start_date',
        ]);

        DB::transaction(function () use ($property, $validated): void {
            foreach ($validated['tenants'] as $tenant) {
                PropertyTenant::updateOrCreate(
                    [
                        'property_id' => $property->id,
                        'tenant_id' => $tenant['id'],
                    ],
                    [
                        'start_date' => $tenant['start_date'],
                        'end_date' => $tenant['end_date'] ?? null,
                        'security_deposit_amount' => $property->security_amount,
                        'security_deposit_status' => 'pending',
                    ]
                );
            }

            $property->loadMissing(['owner', 'manager']);

            $tenancies = PropertyTenant::with([
                'property.owner',
                'property.manager',
                'tenant',
                'rentDeed',
            ])
                ->where('property_id', $property->id)
                ->whereIn('tenant_id', collect($validated['tenants'])->pluck('id'))
                ->get();

            foreach ($tenancies as $tenancy) {
                if ($tenancy->resolveRentDeed()) {
                    Artisan::call('rent:generate', ['tenancy_id' => $tenancy->id]);
                }

                NotificationService::notifyStakeholders(
                    stakeholders: collect([
                        ['role' => 'tenant', 'user' => $tenancy->tenant],
                        ['role' => 'owner', 'user' => $property->owner],
                        ['role' => 'manager', 'user' => $property->manager],
                    ])->filter(fn (array $stakeholder) => $stakeholder['user']),
                    type: 'property',
                    model: $tenancy,
                    context: ['event' => 'tenant_assigned']
                );
            }
        });

        return response()->json(['message' => 'Tenants assigned successfully']);
    }

    /** List tenants of property */
    public function tenants(Property $property)
    {
        $this->authorizePropertyAccess($property);

        $tenants = $property->tenants()->with('profile')->get([
            'users.id',
            'users.name',
            'users.email',
            'property_tenant.start_date',
            'property_tenant.end_date',
        ]);

        return response()->json($tenants);
    }

    public function managers()
    {
        abort_unless(auth()->user()?->hasAnyRole(['owner', 'super-admin']), 403, 'Unauthorized');

        $managers = User::query()
            ->role('property_manager')
            ->with('profile')
            ->get([
                'id',
                'name',
                'email',
            ]);

        return response()->json($managers);
    }

    /** Properties assigned to a tenant */
    public function assignedProperties(Request $request)
    {
        $authUser = auth()->user();
        $requestedUserId = (int) $request->input('userId');

        if (! $authUser->hasRole('super-admin') && (int) $authUser->id !== $requestedUserId) {
            abort(403, 'Unauthorized');
        }

        $user = User::find($requestedUserId);
        if (! $user || ! $user->hasRole('tenant')) {
            return response()->json(['property' => null]);
        }

        $currentMonth = Carbon::now()->startOfMonth()->toDateString();
        $properties = $user->assignedProperties()
            ->with(['latestRentDeed', 'media'])
            ->get();
        $tenancies = PropertyTenant::query()
            ->with('rentDeed')
            ->whereIn('id', $properties->pluck('pivot.id')->filter()->values())
            ->get()
            ->keyBy('id');

        $properties->each(function ($property) use ($currentMonth, $tenancies) {
            $lateFeePolicyService = app(LateFeePolicyService::class);
            $tenancy = $property->pivot;
            $tenancyRecord = $tenancies->get($tenancy->id);
            $rentSchedule = RentSchedule::where('tenancy_id', $tenancy->id)
                ->where('month', $currentMonth)
                ->first();

            $payableNow = RentSchedule::where('tenancy_id', $tenancy->id)
                ->where('due_date', '<=', now()->endOfMonth())
                ->whereIn('status', ['pending', 'overdue'])
                ->orderBy('due_date')
                ->get([
                    'id',
                    'month',
                    'due_date',
                    'amount',
                    'status',
                ]);
            $payableLateFeeSummary = $lateFeePolicyService->summarize(
                $payableNow,
                $property->late_payment_penalty,
                now()
            );

            $overdues = RentSchedule::where('tenancy_id', $tenancy->id)
                ->where('month', '<', $currentMonth)
                ->whereIn('status', ['pending', 'overdue', 'manual_pending'])
                ->orderBy('month', 'asc')
                ->get([
                    'id',
                    'month',
                    'due_date',
                    'amount',
                    'status',
                ]);
            $overdueLateFeeSummary = $lateFeePolicyService->summarize(
                $overdues,
                $property->late_payment_penalty,
                now()
            );

            if ($overdues->isNotEmpty()) {
                $status = $overdues->contains('status', 'manual_pending')
                    ? 'manual_pending'
                    : 'overdue';

                // oldest unpaid due date
                $due_date = $overdues->first()->due_date;
            } else {
                $status = $rentSchedule->status ?? 'pending';
                $due_date = $rentSchedule?->due_date;
            }

            $due_date = $due_date
                ? Carbon::parse($due_date)->format('Y-m-d')
                : null;
            $property->tenancy_id = $tenancy->id;
            $property->has_subscription = (int) ($tenancyRecord?->subscription_active ?? $tenancy->subscription_active ?? 0);
            $property->stripe_subscription_id = $tenancyRecord?->stripe_subscription_id ?? $tenancy->stripe_subscription_id;
            $property->subscription_cancel_at = $this->isoDateTimeOrNull(
                $tenancyRecord?->subscription_cancel_at ?? $tenancy->subscription_cancel_at
            );
            $property->has_rent_deed = (bool) $tenancyRecord?->resolveRentDeed();
            $property->rent_status = $status;
            $property->due_date = $due_date;
            $property->payable_now_base_total = round((float) $payableNow->sum('amount'), 2);
            $property->payable_now_total = round((float) $payableNow->sum('amount') + (float) $payableLateFeeSummary['total'], 2);
            $property->current_month_due_amount = $rentSchedule ? round((float) $rentSchedule->amount, 2) : 0.0;
            $currentMonthLateFee = collect($payableLateFeeSummary['items'])
                ->where('rent_schedule_id', $rentSchedule?->id)
                ->sum('amount');
            $property->current_month_late_fee_amount = round((float) $currentMonthLateFee, 2);
            $property->missed_rent_total = round((float) $overdues->sum('amount'), 2);
            $property->missed_rent_payable_total = round((float) $overdues->sum('amount') + (float) $overdueLateFeeSummary['total'], 2);
            $property->missed_rent_late_fee_total = round((float) $overdueLateFeeSummary['total'], 2);
            $property->autopay_monthly_amount = round((float) ($property->monthly_rent ?? 0), 2);
            $property->late_fee_amount = round((float) $payableLateFeeSummary['total'], 2);
            $property->late_fee_applied = $property->late_fee_amount > 0;
            $property->late_payment_penalty_policy = $property->late_payment_penalty;
            $property->late_fee_policy_active = ! empty($property->late_payment_penalty);
            $property->overdue_months = $overdues->map(fn ($o) => [
                'id' => $o->id,
                'month' => Carbon::parse($o->month)->format('Y-m'),
                'due_date' => Carbon::parse($o->due_date)->format('Y-m-d'),
                'amount' => $o->amount,
                'late_fee_amount' => round((float) collect($overdueLateFeeSummary['items'])->where('rent_schedule_id', $o->id)->sum('amount'), 2),
                'payable_total' => round((float) $o->amount + (float) collect($overdueLateFeeSummary['items'])->where('rent_schedule_id', $o->id)->sum('amount'), 2),
                'status' => $o->status,
            ]);

            $property->overdue_count = $overdues->count();
            $property->overdue_total = round((float) $overdues->sum('amount') + (float) $overdueLateFeeSummary['total'], 2);
            $property->security_deposit_amount = $tenancy?->security_deposit_amount;
            $property->security_deposit_status = $tenancy?->security_deposit_status;
            $property->isPaid = $status === 'paid';
            $property->canPay = (bool) $rentSchedule && in_array($status, ['pending']);

            unset($property->pivot);
        });

        return response()->json(['property' => $properties]);
    }

    /** Pending security deposit approvals */
    public function pendingApprovals()
    {
        $user = auth()->user();

        abort_unless($user->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        $records = PropertyTenant::query()
            ->where('security_deposit_status', 'manual_pending')
            ->whereHas('property', function ($q) use ($user) {
                $this->applyWorkspacePropertyScope($q, $user);
            })
            ->with(['property', 'tenant'])
            ->get()
            ->map(fn ($t) => [
                'tenancy_id' => $t->id,
                'property_name' => $t->property->property_name,
                'tenant_name' => $t->tenant->name,
                'amount' => $t->security_deposit_amount,
            ]);

        return response()->json($records);
    }

    public function dashboardSummary()
    {
        $user = auth()->user();

        abort_unless($user->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        $propertyQuery = Property::query();
        $this->applyWorkspacePropertyScope($propertyQuery, $user);

        $propertyIds = (clone $propertyQuery)->pluck('id');

        if ($propertyIds->isEmpty()) {
            return response()->json([
                'properties' => 0,
                'overdue' => 0,
                'auto_pay_active' => 0,
                'approvals' => 0,
            ]);
        }

        $currentMonth = Carbon::now()->startOfMonth()->toDateString();

        /**
         * Match tenant-facing assignedProperties: overdue = prior months unpaid OR current month row with status overdue.
         */
        $overdueCount = PropertyTenant::query()
            ->whereIn('property_id', $propertyIds)
            ->where(function (Builder $q) use ($currentMonth) {
                $q->whereHas('rentSchedules', function (Builder $rs) use ($currentMonth) {
                    $rs->where('month', '<', $currentMonth)
                        ->whereIn('status', ['pending', 'overdue', 'manual_pending']);
                })->orWhereHas('rentSchedules', function (Builder $rs) use ($currentMonth) {
                    $rs->where('month', $currentMonth)
                        ->where('status', 'overdue');
                });
            })
            ->distinct()
            ->count('property_id');

        $autoPayActiveCount = PropertyTenant::query()
            ->whereIn('property_id', $propertyIds)
            ->where('subscription_active', 1)
            ->distinct()
            ->count('property_id');

        $securityApprovalCount = PropertyTenant::query()
            ->whereIn('property_id', $propertyIds)
            ->where('security_deposit_status', 'manual_pending')
            ->count();

        $rentApprovalCount = RentSchedule::query()
            ->join('property_tenant', 'property_tenant.id', '=', 'rent_schedules.tenancy_id')
            ->whereIn('property_tenant.property_id', $propertyIds)
            ->where('rent_schedules.status', 'manual_pending')
            ->count();

        return response()->json([
            'properties' => (clone $propertyQuery)->count(),
            'overdue' => $overdueCount,
            'auto_pay_active' => $autoPayActiveCount,
            'approvals' => $securityApprovalCount + $rentApprovalCount,
        ]);
    }

    public function pendingRentApprovals()
    {
        $user = auth()->user();

        abort_unless($user->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        $records = RentSchedule::query()
            ->where('status', 'manual_pending') // rent manual approval requests

            // Join property_tenant to get tenant + property relation
            ->whereHas('tenancy.property', function ($q) use ($user) {
                $this->applyWorkspacePropertyScope($q, $user);
            })

            ->with([
                'tenancy.tenant',
                'tenancy.property',
            ])

            ->get()

            ->map(fn ($r) => [
                'rent_schedule_id' => $r->id,
                'month' => $r->month,
                'tenancy_id' => $r->tenancy_id,
                'property_name' => $r->tenancy->property->property_name ?? null,
                'tenant_name' => $r->tenancy->tenant->name ?? null,
                'amount' => $r->amount,
                'due_date' => $r->due_date,
            ]);

        return response()->json($records);
    }

    /** Approve or reject manual security deposit */
    public function approveManual(Request $request)
    {
        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
            'status' => 'required|in:approved,declined',
        ]);

        $user = auth()->user();
        abort_unless($user->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        $tenancy = PropertyTenant::with(['property.owner', 'property.manager', 'tenant'])
            ->where('id', $request->tenancy_id)
            ->where('security_deposit_status', 'manual_pending')
            ->whereHas('property', function ($q) use ($user) {
                if ($user->hasRole('super-admin')) {
                    return;
                }

                if ($user->hasRole('owner')) {
                    $q->where('user_id', $user->id);
                }

                if ($user->hasRole('property_manager')) {
                    $q->where('manager_id', $user->id);
                }
            })
            ->firstOrFail();

        $text = $request->status === 'approved' ? 'approved' : 'rejected';

        DB::transaction(function () use ($tenancy, $request) {

            // Update payment records
            Payment::where([
                'property_id' => $tenancy->property_id,
                'tenant_id' => $tenancy->tenant_id,
                'type' => 'security_deposit',
                'payment_mode' => 'manual',
                'status' => 'pending',
            ])->where(function ($query) use ($tenancy) {
                $query->where('tenancy_id', $tenancy->id)
                    ->orWhereNull('tenancy_id');
            })->update([
                'status' => $request->status === 'approved' ? 'succeeded' : 'rejected',
            ]);

            // Update tenancy
            $tenancy->security_deposit_status = $request->status === 'approved' ? 'paid' : 'pending';
            $tenancy->save();

            // Users list (SAME STYLE AS JOB)
            broadcast(new ManualSecurityDepositEvent($tenancy))->toOthers();
            Artisan::call('rent:update-overdue');
            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'security_deposit',
                null,
                [
                    'event' => $request->status,
                    'payment_type' => 'security_deposit',
                    'amount' => round((float) $tenancy->security_deposit_amount, 2),
                ]
            );

            if ($request->status === 'approved') {
                $payment = Payment::query()
                    ->where('property_id', $tenancy->property_id)
                    ->where('tenant_id', $tenancy->tenant_id)
                    ->where('type', 'security_deposit')
                    ->where('payment_mode', 'manual')
                    ->where('status', 'succeeded')
                    ->where(function ($query) use ($tenancy) {
                        $query->where('tenancy_id', $tenancy->id)
                            ->orWhereNull('tenancy_id');
                    })
                    ->latest('id')
                    ->first();

                if ($payment) {
                    app(InvoiceIssueService::class)->issueTenantInvoice(
                        tenancy: $tenancy,
                        type: 'security_deposit',
                        sourceKey: 'deposit:manual:payment:'.$payment->id,
                        context: [
                            'primary_payment_id' => $payment->id,
                            'paid_at' => $payment->updated_at ?? now(),
                        ]
                    );
                }
            }
        });

        return response()->json(['message' => "Security deposit {$text}"]);
    }

    public function approveRentManual(Request $request)
    {
        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
            'rent_schedule_id' => 'required|exists:rent_schedules,id',
            'status' => 'required|in:approved,declined',
        ]);

        $authUser = auth()->user();
        abort_unless($authUser->hasAnyRole(['owner', 'property_manager', 'super-admin']), 403, 'Unauthorized');

        // Verify tenancy belongs to owner/manager
        $tenancy = PropertyTenant::with(['property.owner', 'property.manager', 'tenant'])
            ->where('id', $request->tenancy_id)
            ->whereHas('property', function ($q) use ($authUser) {
                if ($authUser->hasRole('super-admin')) {
                    return;
                }

                if ($authUser->hasRole('owner')) {
                    $q->where('user_id', $authUser->id);
                }

                if ($authUser->hasRole('property_manager')) {
                    $q->where('manager_id', $authUser->id);
                }
            })
            ->firstOrFail();

        $text = $request->status === 'approved' ? 'APPROVED' : 'DECLINED';

        DB::transaction(function () use ($tenancy, $request) {

            $rentSchedule = RentSchedule::where('tenancy_id', $tenancy->id)
                ->where('status', 'manual_pending')
                ->where('id', $request->rent_schedule_id)
                ->whereDate('due_date', '<=', now()->endOfMonth())
                ->firstOrFail();
            $rentSchedule->status = $request->status === 'approved' ? 'paid' : 'pending';
            $rentSchedule->save();

            Payment::where('tenant_id', $tenancy->tenant_id)
                ->where('property_id', $tenancy->property_id)
                ->where('type', 'rent_deposit')
                ->where('rent_schedule_id', $request->rent_schedule_id)
                ->where('payment_mode', 'manual')
                ->where('status', 'pending')
                ->where(function ($query) use ($tenancy) {
                    $query->where('tenancy_id', $tenancy->id)
                        ->orWhereNull('tenancy_id');
                })
                ->update([
                    'status' => $request->status === 'approved' ? 'succeeded' : 'rejected',
                ]);

            // Users
            broadcast(new ManualRentDepositEvent($tenancy))->toOthers();
            Artisan::call('rent:update-overdue');
            $tenancy->setRelation('rentSchedule', collect([$rentSchedule]));
            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'rent_deposit',
                null,
                [
                    'event' => $request->status,
                    'payment_type' => 'rent_deposit',
                    'amount' => round((float) $rentSchedule->amount, 2),
                    'due_date' => $rentSchedule->due_date,
                ]
            );

            if ($request->status === 'approved') {
                $payment = Payment::query()
                    ->where('tenant_id', $tenancy->tenant_id)
                    ->where('property_id', $tenancy->property_id)
                    ->where('type', 'rent_deposit')
                    ->where('rent_schedule_id', $request->rent_schedule_id)
                    ->where('payment_mode', 'manual')
                    ->where('status', 'succeeded')
                    ->where(function ($query) use ($tenancy) {
                        $query->where('tenancy_id', $tenancy->id)
                            ->orWhereNull('tenancy_id');
                    })
                    ->latest('id')
                    ->first();

                if ($payment) {
                    app(InvoiceIssueService::class)->issueTenantInvoice(
                        tenancy: $tenancy,
                        type: 'rent',
                        sourceKey: 'rent:manual:payment:'.$payment->id,
                        context: [
                            'primary_payment_id' => $payment->id,
                            'rent_schedules' => collect([$rentSchedule]),
                            'paid_at' => $payment->updated_at ?? now(),
                            'due_date' => $rentSchedule->due_date,
                        ]
                    );
                }
            }
        });

        return response()->json([
            'message' => "Rent payment {$text}",
        ]);
    }

    public function assignManager(Request $request, Property $property)
    {
        $this->authorizePropertyAssignment($property);

        $request->validate([
            'manager_id' => 'required|exists:users,id',
        ]);

        $manager = User::findOrFail($request->manager_id);

        if (! $manager->hasRole('property_manager')) {
            return response()->json(['message' => 'Selected user is not a property manager'], 422);
        }

        DB::transaction(function () use ($property, $manager) {
            $tenancy = PropertyTenant::with([
                'property.owner',
                'property.manager',
                'tenant',
            ])
                ->where('property_id', $property->id)
                ->orderBy('id', 'desc')
                ->first();

            // Assign manager
            $property->manager_id = $manager->id;
            $property->save();

            // Users list (Job-style)
            NotificationService::notifyStakeholders(
                stakeholders: collect([
                    ['role' => 'manager', 'user' => $manager],
                    ['role' => 'owner', 'user' => $property->owner],
                    ['role' => 'tenant', 'user' => $tenancy?->tenant],
                ])->filter(fn (array $stakeholder) => $stakeholder['user']),
                type: 'property',
                model: $tenancy ?? $property,
                context: ['event' => 'manager_assigned']
            );
        });

        return response()->json([
            'message' => 'Manager assigned successfully',
            'manager' => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
            ],
        ]);
    }
}
