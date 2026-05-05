<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Bid;
use App\Models\TimeLog;
use Carbon\Carbon;

class TenantAdminController extends Controller
{
    public function bidders()
    {
        $now = Carbon::now('UTC');

        $bidders = User::role('Bidder')
            ->withCount('bids')
            ->get()
            ->map(function ($bidder) use ($now) {
                $activeShift = TimeLog::where('user_id', $bidder->id)
                    ->whereNull('logout_time')
                    ->latest('login_time')
                    ->first();

                $todayHours = TimeLog::where('user_id', $bidder->id)
                    ->where('date', $now->toDateString())
                    ->get()
                    ->sum(function ($log) use ($now) {
                        if ($log->logout_time) {
                            return abs((float) $log->total_hours);
                        }
                        return abs($now->floatDiffInHours($log->login_time));
                    });

                $bidsToday = Bid::where('user_id', $bidder->id)
                    ->whereDate('created_at', $now->toDateString())
                    ->count();

                $bidsThisWeek = Bid::where('user_id', $bidder->id)
                    ->where('created_at', '>=', $now->copy()->startOfWeek())
                    ->count();

                return [
                    'id' => $bidder->id,
                    'name' => $bidder->name,
                    'email' => $bidder->email,
                    'is_active' => $bidder->is_active,
                    'total_bids' => $bidder->bids_count,
                    'bids_today' => $bidsToday,
                    'bids_this_week' => $bidsThisWeek,
                    'is_online' => (bool) $activeShift,
                    'punched_in_at' => $activeShift?->login_time?->toIso8601String(),
                    'today_hours' => round($todayHours, 2),
                    'joined' => $bidder->created_at?->toIso8601String(),
                ];
            });

        return response()->json(['bidders' => $bidders]);
    }

    public function efficiency()
    {
        $now = Carbon::now('UTC');
        $bids = Bid::with('user:id,name,email')->orderBy('created_at', 'desc')->get();

        $statusCounts = $bids->groupBy('status')->map->count();
        $total = $bids->count();
        $hired = $statusCounts->get('Hired', 0);
        $bidsToday = $bids->filter(fn ($b) => $b->created_at->isToday())->count();

        return response()->json([
            'bids' => $bids->map(fn ($b) => [
                'bid_id' => $b->bid_id,
                'job_url' => $b->job_url,
                'job_title' => $b->job_title,
                'clean_job_id' => $b->clean_job_id,
                'platform_name' => $b->platform_name,
                'connects_used' => $b->connects_used,
                'status' => $b->status,
                'user' => $b->user ? ['id' => $b->user->id, 'name' => $b->user->name, 'email' => $b->user->email] : null,
                'created_at' => $b->created_at->toIso8601String(),
            ]),
            'summary' => [
                'total' => $total,
                'bids_today' => $bidsToday,
                'conversion_rate' => $total > 0 ? round(($hired / $total) * 100, 1) : 0,
                'status_counts' => $statusCounts,
                'connects_used' => $bids->sum('connects_used'),
            ],
        ]);
    }

    public function updateBidStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:Submitted,Interviewing,Hired,Rejected']);

        $bid = Bid::findOrFail($id);
        $bid->update(['status' => $request->status]);
        $bid->load('user:id,name,email');

        return response()->json([
            'status' => 'success',
            'bid' => [
                'bid_id' => $bid->bid_id,
                'job_url' => $bid->job_url,
                'job_title' => $bid->job_title,
                'platform_name' => $bid->platform_name,
                'connects_used' => $bid->connects_used,
                'status' => $bid->status,
                'user' => $bid->user ? ['id' => $bid->user->id, 'name' => $bid->user->name] : null,
                'created_at' => $bid->created_at->toIso8601String(),
            ],
        ]);
    }
}
