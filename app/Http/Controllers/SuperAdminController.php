<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Bid;

class SuperAdminController extends Controller
{
    public function tenants()
    {
        $tenants = Tenant::all()->map(function ($tenant) {
            $userCount = User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->tenant_id)
                ->count();

            $bidderCount = User::withoutGlobalScopes()
                ->where('tenant_id', $tenant->tenant_id)
                ->role('Bidder')
                ->count();

            $bidCount = Bid::withoutGlobalScopes()
                ->where('tenant_id', $tenant->tenant_id)
                ->count();

            return [
                'tenant_id' => $tenant->tenant_id,
                'company_name' => $tenant->company_name,
                'subscription_plan' => $tenant->subscription_plan,
                'subscription_status' => $tenant->subscription_status,
                'face_recognition_enabled' => (bool) $tenant->face_recognition_enabled,
                'user_count' => $userCount,
                'bidder_count' => $bidderCount,
                'bid_count' => $bidCount,
                'created_at' => $tenant->created_at?->toIso8601String(),
            ];
        });

        $totalUsers = User::withoutGlobalScopes()->count();
        $totalBidders = User::withoutGlobalScopes()->role('Bidder')->count();
        $totalBids = Bid::withoutGlobalScopes()->count();
        $bidsToday = Bid::withoutGlobalScopes()->whereDate('created_at', today())->count();

        return response()->json([
            'tenants' => $tenants,
            'stats' => [
                'total_tenants' => $tenants->count(),
                'total_users' => $totalUsers,
                'total_bidders' => $totalBidders,
                'total_bids' => $totalBids,
                'bids_today' => $bidsToday,
            ],
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:active,suspended,past_due']);

        $tenant = Tenant::findOrFail($id);
        $tenant->update(['subscription_status' => $request->status]);

        return response()->json(['status' => 'success', 'tenant' => $tenant]);
    }

    public function toggleFaceRecognition(Request $request, $id)
    {
        $request->validate(['enabled' => 'required|boolean']);

        $tenant = Tenant::findOrFail($id);
        $tenant->update(['face_recognition_enabled' => $request->enabled]);

        return response()->json([
            'status' => 'success',
            'face_recognition_enabled' => (bool) $tenant->face_recognition_enabled,
        ]);
    }
}
