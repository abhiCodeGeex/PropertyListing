<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PropertyCommissionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasRole('super-admin'), 403, 'Unauthorized');

        $query = Property::query()
            ->with([
                'owner:id,name,email,stripe_connect_account_id,stripe_connect_details_submitted,stripe_connect_charges_enabled,stripe_connect_payouts_enabled',
                'manager:id,name,email,stripe_connect_account_id,stripe_connect_details_submitted,stripe_connect_charges_enabled,stripe_connect_payouts_enabled',
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($builder) use ($search) {
                $builder->where('property_name', 'like', "%{$search}%")
                    ->orWhereHas('owner', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('manager', fn ($user) => $user->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate((int) $request->integer('per_page', 10)));
    }

    public function update(Request $request, Property $property)
    {
        abort_unless(auth()->user()?->hasRole('super-admin'), 403, 'Unauthorized');

        $data = $request->validate([
            'owner_commission_percent' => 'required|numeric|min:0|max:100',
            'manager_commission_percent' => 'required|numeric|min:0|max:100',
        ], [], [
            'owner_commission_percent' => 'owner commission',
            'manager_commission_percent' => 'manager commission',
        ]);

        $ownerPercent = round((float) $data['owner_commission_percent'], 2);
        $managerPercent = round((float) $data['manager_commission_percent'], 2);

        if (($ownerPercent + $managerPercent) > 100.0) {
            throw ValidationException::withMessages([
                'manager_commission_percent' => ['Owner and manager commission together cannot exceed 100%.'],
            ]);
        }

        $property->forceFill([
            'owner_commission_percent' => $ownerPercent,
            'manager_commission_percent' => $managerPercent,
        ])->save();

        return response()->json([
            'message' => 'Commission settings updated successfully.',
            'property' => $property->fresh([
                'owner:id,name,email,stripe_connect_account_id,stripe_connect_details_submitted,stripe_connect_charges_enabled,stripe_connect_payouts_enabled',
                'manager:id,name,email,stripe_connect_account_id,stripe_connect_details_submitted,stripe_connect_charges_enabled,stripe_connect_payouts_enabled',
            ]),
        ]);
    }
}
