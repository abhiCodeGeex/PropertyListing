<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RentHistoryController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $perPage = $request->get('per_page', 10);
        $search = $request->get('search');
        $propertyId = $request->get('propertyId');

        $query = Payment::with([
            'tenant:id,name',
            'property:id,property_name,user_id,manager_id',
            'rentSchedule.tenancy.property:id,property_name',
        ]);

        // Super admin = full access
        if (! $user->hasRole('super-admin')) {

            $query->where(function ($q) use ($user) {

                // Tenant payments
                if ($user->hasRole('tenant')) {
                    $q->orWhere('tenant_id', $user->id);
                }

                // Owner properties
                if ($user->hasRole('owner')) {
                    $q->orWhereHas('property', function ($p) use ($user) {
                        $p->where('user_id', $user->id);
                    });
                }

                // Manager properties
                if ($user->hasRole('property_manager')) {
                    $q->orWhereHas('property', function ($p) use ($user) {
                        $p->where('manager_id', $user->id);
                    });
                }
            });
        }

        if ($search) {
            $query->where(function ($q) use ($search) {

                $q->whereHas('tenant', function ($t) use ($search) {
                    $t->where('name', 'LIKE', "%{$search}%");
                })

                    ->orWhereHas('rentSchedule.tenancy.property', function ($p) use ($search) {
                        $p->where('property_name', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($propertyId) {
            $query->where('property_id', $propertyId);
        }

        $payments = $query
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $payments->getCollection()->transform(function ($payment) {

            $propertyName = null;
            if ($payment->rentSchedule?->tenancy?->property) {
                $propertyName = $payment->rentSchedule->tenancy->property->property_name;
            }

            if ($payment->property_id) {
                $propertyName = optional(
                    $payment->property
                )->property_name;
            }

            return [
                'month' => $payment->rentSchedule?->month
                    ? Carbon::parse($payment->rentSchedule->month)->format('F Y')
                    : null,

                'amount' => $payment->amount,
                'type' => match ($payment->type) {
                    'security_deposit' => 'Security Deposit',
                    'overdue' => 'Overdue Rent',
                    'late_fee' => 'Late Fee',
                    'rent',
                    'rent_deposit' => 'Rent',
                    default => 'Rent',
                },
                'status' => $payment->status,
                'paid_on' => ($payment->status === 'pending'
                    ? $payment->created_at
                    : ($payment->updated_at ?? $payment->created_at))?->toIso8601String(),
                'invoice_id' => $payment->stripe_invoice_id,
                'propertyName' => $propertyName,
                'paymentMode' => $payment->payment_mode,
                'tenantName' => $payment->tenant?->name,
                'failureReason' => $payment->failure_reason,
            ];
        });

        return response()->json($payments);
    }
}
