<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function revenueReport(Request $request)
    {
        $user = auth()->user();

        $query = Payment::query()
            ->whereIn('status', ['paid', 'succeeded'])
            ->where('amount', '>', 0);

        // Date filter
        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            // NO PROPERTY FILTER
        } else {
            abort_unless($user->hasAnyRole(['owner', 'property_manager']), 403, 'Unauthorized');

            // Owner OR Manager (UNION)
            $query->whereHas('property', function ($q) use ($user) {
                $q->where(function ($sub) use ($user) {

                    if ($user->hasRole('owner')) {
                        $sub->orWhere('user_id', $user->id);
                    }

                    if ($user->hasRole('property_manager')) {
                        $sub->orWhere('manager_id', $user->id);
                    }
                });
            });
        }

        // Search
        if ($request->search) {
            $query->whereHas('property', function ($q) use ($request) {
                $q->where('property_name', 'like', "%{$request->search}%");
            });
        }

        // Pagination
        $data = $query->with('property')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 10);

        // Total Revenue
        $totalRevenue = (clone $query)->sum('amount');

        return response()->json([
            'data' => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
            'total_revenue' => $totalRevenue,
        ]);
    }
}
