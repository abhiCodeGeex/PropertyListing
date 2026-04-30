<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\RentDeedMail;
use App\Models\Property;
use App\Models\PropertyTenant;
use App\Models\RentDeed;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RentDeedController extends Controller
{
    private function authorizePropertyAccess(Property $property): void
    {
        $user = auth()->user();

        if ($user->hasRole('super-admin')) {
            return;
        }

        if ($user->hasRole('owner') && (int) $property->user_id === (int) $user->id) {
            return;
        }

        if ($user->hasRole('property_manager') && (int) $property->manager_id === (int) $user->id) {
            return;
        }

        abort(403, 'Unauthorized');
    }

    private function authorizeRentDeedAccess(RentDeed $rentDeed): void
    {
        $rentDeed->loadMissing('property');
        abort_if(! $rentDeed->property, 404, 'Rent deed property not found');

        $this->authorizePropertyAccess($rentDeed->property);
    }

    private function latestTenancyForRentDeed(int $propertyId, ?int $tenantId = null): ?PropertyTenant
    {
        return PropertyTenant::query()
            ->where('property_id', $propertyId)
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('id')
            ->first();
    }

    private function syncSchedulesForProperty(int $propertyId, ?int $tenantId = null): void
    {
        $tenancy = $this->latestTenancyForRentDeed($propertyId, $tenantId);

        if (! $tenancy) {
            return;
        }

        Artisan::call('rent:generate', ['tenancy_id' => $tenancy->id]);
        Artisan::call('rent:update-overdue');
    }

    private function resolveRentDeedParties(Property $property): array
    {
        $tenantId = $property->tenants->pluck('id')->first();
        $ownerId = $property->owner?->id;

        if (empty($tenantId)) {
            throw ValidationException::withMessages([
                'property_id' => ['Assign a tenant to this property before creating a rent deed.'],
            ]);
        }

        if (empty($ownerId)) {
            throw ValidationException::withMessages([
                'property_id' => ['Owner is not associated with the property.'],
            ]);
        }

        return [$tenantId, $ownerId];
    }

    private function sendRentDeedToPropertyUsers(RentDeed $rentDeed): void
    {
        $rentDeed->loadMissing(['property.owner', 'property.manager', 'owner', 'tenant']);

        collect([
            $rentDeed->tenant,
            $rentDeed->owner,
            $rentDeed->property?->manager,
        ])
            ->filter(fn ($user) => $user && filled($user->email))
            ->unique(fn ($user) => strtolower($user->email))
            ->each(function ($user) use ($rentDeed) {
                Mail::to($user->email)->queue(new RentDeedMail($rentDeed, $user));
            });
    }

    private function notifyRentDeedStakeholders(RentDeed $rentDeed, string $event): void
    {
        $rentDeed->loadMissing(['property.owner', 'property.manager', 'owner', 'tenant']);

        NotificationService::notifyStakeholders(
            stakeholders: collect([
                ['role' => 'tenant', 'user' => $rentDeed->tenant],
                ['role' => 'owner', 'user' => $rentDeed->owner],
                ['role' => 'manager', 'user' => $rentDeed->property?->manager],
            ])->filter(fn (array $stakeholder) => $stakeholder['user']),
            type: 'agreement',
            model: $rentDeed,
            context: ['event' => $event]
        );
    }

    private function rentDeedRules(): array
    {
        return [
            'agreementNumber' => 'required|string|max:255',
            'agreementDate' => 'required|date',
            'property_id' => 'required|integer|exists:properties,id',
            'rentDueDate' => 'required|integer|min:1|max:20',
            'maintenanceCharges' => 'required|string|max:255',
            'otherDetails' => 'nullable|string',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'rent_deed_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    private function rentDeedAttributes(): array
    {
        return [
            'agreementNumber' => 'agreement number',
            'agreementDate' => 'agreement date',
            'property_id' => 'property',
            'rentDueDate' => 'rent due date',
            'maintenanceCharges' => 'maintenance',
            'otherDetails' => 'other details',
        ];
    }

    private function rentDeedPayload(array $validated): array
    {
        return [
            'agreement_number' => trim($validated['agreementNumber']),
            'agreement_date' => Carbon::parse($validated['agreementDate'])->format('Y-m-d'),
            'property_id' => $validated['property_id'],
            'rent_due_date' => $validated['rentDueDate'],
            'maintenance_charges' => trim($validated['maintenanceCharges']),
            'other_details' => $this->nullableTrimmed($validated['otherDetails'] ?? null),
        ];
    }

    private function validatedRentDeedData(Request $request): array
    {
        $propertyId = $request->input('property_id');

        if ($propertyId === null && $request->has('propertyId')) {
            $propertyField = $request->input('propertyId');
            $propertyId = is_array($propertyField) ? ($propertyField['id'] ?? null) : $propertyField;
        }

        $data = array_merge($request->all(), [
            'property_id' => $propertyId,
        ]);

        return validator($data, $this->rentDeedRules(), [], $this->rentDeedAttributes())->validate();
    }

    private function uploadedRentDeedFile(Request $request): ?UploadedFile
    {
        return $request->file('rent_deed_file') ?? $request->file('file');
    }

    private function storeRentDeedFile(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $generatedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        return $file->storeAs('rent_deeds', $generatedName, 'public');
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** List Rent Deeds with pagination and search */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = RentDeed::with(['property', 'owner', 'tenant', 'property.manager'])->latest();
        // return $user->hasRole('admin');
        if (! $user->hasRole('super-admin')) {
            $query->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhere('tenant_id', $user->id)
                    ->orWhereHas('property', function ($p) use ($user) {
                        $p->where('manager_id', $user->id);
                    });
            });
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('agreement_number', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('tenant', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('property.manager', fn ($q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('propertyId')) {
            $query->where('property_id', $request->propertyId);
        }

        $rentDeeds = $query->paginate(10);

        $tenancies = PropertyTenant::query()
            ->whereIn('property_id', $rentDeeds->getCollection()->pluck('property_id')->filter()->unique()->values())
            ->orderByDesc('id')
            ->get()
            ->unique(fn (PropertyTenant $tenancy) => $tenancy->property_id.':'.$tenancy->tenant_id)
            ->keyBy(fn (PropertyTenant $tenancy) => $tenancy->property_id.':'.$tenancy->tenant_id);

        $rentDeeds->getCollection()->transform(function (RentDeed $rentDeed) use ($tenancies) {
            $tenancy = $tenancies->get($rentDeed->property_id.':'.$rentDeed->tenant_id);

            $rentDeed->setAttribute('tenancy_id', $tenancy?->id);
            $rentDeed->setAttribute('subscription_active', $tenancy?->subscription_active);
            $rentDeed->setAttribute('subscription_cancel_at', $tenancy?->subscription_cancel_at?->toIso8601String());

            return $rentDeed;
        });

        return response()->json($rentDeeds);
    }

    public function store(Request $request)
    {
        $data = $this->validatedRentDeedData($request);
        $payload = $this->rentDeedPayload($data);
        $rentDeed = null;
        $storedFilePath = null;
        $uploadedFile = $this->uploadedRentDeedFile($request);

        DB::beginTransaction();

        try {
            $property = Property::where('id', $payload['property_id'])->with('owner', 'tenants')->first();
            abort_if(! $property, 404, 'Property not found');
            $this->authorizePropertyAccess($property);
            [$tenant_id, $owner_id] = $this->resolveRentDeedParties($property);

            $rentDeedData = [
                'agreement_number' => $payload['agreement_number'],
                'agreement_date' => $payload['agreement_date'],
                'owner_id' => $owner_id,
                'tenant_id' => $tenant_id,
                'property_id' => $payload['property_id'],
                'rent_due_date' => $payload['rent_due_date'],
                'maintenance_charges' => $payload['maintenance_charges'],
                'other_details' => $payload['other_details'],
            ];

            if ($uploadedFile) {
                $storedFilePath = $this->storeRentDeedFile($uploadedFile);
                $rentDeedData['file_path'] = $storedFilePath;
            }

            $rentDeed = RentDeed::create($rentDeedData);

            DB::commit();
            $this->syncSchedulesForProperty($payload['property_id'], $tenant_id);
            $this->sendRentDeedToPropertyUsers($rentDeed);
            $this->notifyRentDeedStakeholders($rentDeed, 'created');

            return response()->json([
                'message' => 'Rent Deed created successfully!',
                'rentDeed' => $rentDeed->load(['property', 'owner', 'tenant']),
            ], 201);
        } catch (HttpExceptionInterface $e) {
            DB::rollBack();
            if ($storedFilePath) {
                Storage::disk('public')->delete($storedFilePath);
            }
            throw $e;
        } catch (ValidationException $e) {
            DB::rollBack();
            if ($storedFilePath) {
                Storage::disk('public')->delete($storedFilePath);
            }
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            if ($storedFilePath) {
                Storage::disk('public')->delete($storedFilePath);
            }
            Log::error('Rent deed save failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'We could not save the rent deed. Please try again.',
            ], 500);
        }
    }

    /** Update Rent Deed */
    public function update(Request $request, RentDeed $rentDeed)
    {
        $this->authorizeRentDeedAccess($rentDeed);

        $data = $this->validatedRentDeedData($request);
        $payload = $this->rentDeedPayload($data);
        $property = Property::where('id', $payload['property_id'])->with('owner', 'tenants')->firstOrFail();
        $this->authorizePropertyAccess($property);
        [$tenantId, $ownerId] = $this->resolveRentDeedParties($property);

        $previousPropertyId = $rentDeed->property_id;
        $previousFilePath = $rentDeed->file_path;
        $newFilePath = null;
        $uploadedFile = $this->uploadedRentDeedFile($request);

        try {
            if ($uploadedFile) {
                $newFilePath = $this->storeRentDeedFile($uploadedFile);
            }

            DB::transaction(function () use ($rentDeed, $payload, $tenantId, $ownerId, $newFilePath) {
                $updateData = [
                    'agreement_number' => $payload['agreement_number'],
                    'agreement_date' => $payload['agreement_date'],
                    'owner_id' => $ownerId,
                    'tenant_id' => $tenantId,
                    'property_id' => $payload['property_id'],
                    'rent_due_date' => $payload['rent_due_date'],
                    'maintenance_charges' => $payload['maintenance_charges'],
                    'other_details' => $payload['other_details'],
                ];

                if ($newFilePath) {
                    $updateData['file_path'] = $newFilePath;
                }

                $rentDeed->update($updateData);
            });
        } catch (\Throwable $e) {
            if ($newFilePath) {
                Storage::disk('public')->delete($newFilePath);
            }

            throw $e;
        }

        if ($newFilePath && $previousFilePath) {
            Storage::disk('public')->delete($previousFilePath);
        }

        $this->syncSchedulesForProperty($payload['property_id'], $tenantId);

        if ($previousPropertyId !== $payload['property_id']) {
            $this->syncSchedulesForProperty($previousPropertyId, $rentDeed->tenant_id);
        }

        $this->sendRentDeedToPropertyUsers($rentDeed);
        $this->notifyRentDeedStakeholders($rentDeed, 'updated');

        return response()->json([
            'message' => 'Rent Deed updated successfully!',
            'rentDeed' => $rentDeed->load(['property', 'owner', 'tenant']),
        ]);
    }

    /** Delete Rent Deed */
    public function destroy(RentDeed $rentDeed)
    {
        $this->authorizeRentDeedAccess($rentDeed);

        if ($rentDeed->file_path) {
            Storage::disk('public')->delete($rentDeed->file_path);
        }

        $rentDeed->delete();

        return response()->json(['message' => 'Rent Deed deleted successfully!']);
    }

    /** List Owners */
    public function owners()
    {
        abort_unless(auth()->user()?->hasAnyRole(['owner', 'super-admin', 'property_manager']), 403, 'Unauthorized');

        $owners = User::role(['owner', 'super-admin'])->get(['id', 'name', 'email']);

        return response()->json($owners);
    }

    /** List Tenants */
    public function tenants()
    {
        abort_unless(auth()->user()?->hasAnyRole(['owner', 'super-admin', 'property_manager']), 403, 'Unauthorized');

        $tenants = User::role('tenant')->get(['id', 'name', 'email']);

        return response()->json($tenants);
    }
}
