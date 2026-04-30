<?php

namespace App\Http\Controllers\API;

use App\Events\ManualRentDepositEvent;
use App\Events\ManualSecurityDepositEvent;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->get('q');

        $tenants = User::with('profile')
            ->whereHas('roles', fn ($q) => $q->where('name', 'tenant'))
            ->where(function ($q2) use ($query) {
                $q2->where('email', 'like', "%$query%")
                    ->orWhere('name', 'like', "%$query%");
            })
            ->select('*')
            ->get();

        return response()->json($tenants);
    }

    public function requestManualSecurityDeposit(Request $request)
    {
        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
        ]);

        $tenantId = auth()->id();

        $tenancy = PropertyTenant::with([
            'property.owner',
            'property.manager',
            'tenant',
        ])
            ->where('id', $request->tenancy_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        try {
            $created = DB::transaction(function () use ($tenancy, $tenantId) {
                $alreadyRequested = Payment::where('property_id', $tenancy->property_id)
                    ->where('tenant_id', $tenantId)
                    ->where('tenancy_id', $tenancy->id)
                    ->where('type', 'security_deposit')
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyRequested) {
                    return false;
                }

                $tenancy->security_deposit_status = 'manual_pending';
                $tenancy->save();

                Payment::create([
                    'tenant_id' => $tenantId,
                    'tenancy_id' => $tenancy->id,
                    'rent_schedule_id' => null,
                    'property_id' => $tenancy->property_id,
                    'amount' => $tenancy->security_deposit_amount,
                    'type' => 'security_deposit',
                    'status' => 'pending',
                    'payment_mode' => 'manual',
                ]);

                return true;
            });

            if (! $created) {
                return response()->json([
                    'message' => 'Security deposit request already pending.',
                ]);
            }

            $tenancy->loadMissing(['property.owner', 'property.manager', 'tenant']);
            broadcast(new ManualSecurityDepositEvent($tenancy));
            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'security_deposit',
                null,
                [
                    'event' => 'manual_requested',
                    'payment_type' => 'security_deposit',
                    'amount' => round((float) $tenancy->security_deposit_amount, 2),
                ]
            );

            return response()->json([
                'message' => 'Owner approval required',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    public function requestManualRentDeposit(Request $request)
    {
        $request->validate([
            'tenancy_id' => 'required|exists:property_tenant,id',
        ]);

        $tenantId = auth()->id();

        $tenancy = PropertyTenant::with([
            'property.owner',
            'property.manager',
            'tenant',
        ])
            ->where('id', $request->tenancy_id)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        try {
            $created = DB::transaction(function () use ($tenancy, $tenantId) {
                $unpaidSchedules = RentSchedule::where('tenancy_id', $tenancy->id)
                    ->where('status', '!=', 'paid')
                    ->where('due_date', '<=', now()->endOfMonth())
                    ->lockForUpdate()
                    ->get();

                if ($unpaidSchedules->isEmpty()) {
                    return false;
                }

                RentSchedule::whereIn('id', $unpaidSchedules->pluck('id'))
                    ->update(['status' => 'manual_pending']);

                foreach ($unpaidSchedules as $schedule) {
                    $alreadyRequested = Payment::where('property_id', $tenancy->property_id)
                        ->where('tenant_id', $tenantId)
                        ->where('tenancy_id', $tenancy->id)
                        ->where('type', 'rent_deposit')
                        ->where('status', 'pending')
                        ->where('rent_schedule_id', $schedule->id)
                        ->lockForUpdate()
                        ->exists();

                    if ($alreadyRequested) {
                        continue;
                    }

                    Payment::create([
                        'tenant_id' => $tenantId,
                        'tenancy_id' => $tenancy->id,
                        'rent_schedule_id' => $schedule->id,
                        'property_id' => $tenancy->property_id,
                        'amount' => $schedule->amount,
                        'type' => 'rent_deposit',
                        'status' => 'pending',
                        'payment_mode' => 'manual',
                    ]);
                }

                return true;
            });

            if (! $created) {
                return response()->json([
                    'message' => 'No unpaid rents are available for manual approval.',
                ], 422);
            }

            $tenancy->loadMissing(['property.owner', 'property.manager', 'tenant']);
            $manualPendingSchedules = RentSchedule::where('tenancy_id', $tenancy->id)
                ->where('status', 'manual_pending')
                ->where('due_date', '<=', now()->endOfMonth())
                ->get();
            $tenancy->setRelation('rentSchedule', $manualPendingSchedules);
            broadcast(new ManualRentDepositEvent($tenancy));
            NotificationService::notifyTenancyStakeholders(
                $tenancy,
                'rent_deposit',
                null,
                [
                    'event' => 'manual_requested',
                    'payment_type' => 'rent_deposit',
                    'amount' => round((float) $manualPendingSchedules->sum('amount'), 2),
                    'due_date' => $manualPendingSchedules->max('due_date'),
                ]
            );

            return response()->json([
                'message' => 'Owner approval required',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }
    }
}
