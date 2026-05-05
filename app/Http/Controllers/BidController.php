<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Bid;
use App\Services\JobUrlParserService;
use Carbon\Carbon;

class BidController extends Controller
{
    public function checkCollision(Request $request, JobUrlParserService $parser)
    {
        $request->validate(['url' => 'required|url']);

        $cleanId = $parser->extractCleanJobId($request->url);

        if (!$cleanId) {
            return response()->json(['status' => 'error', 'message' => 'Invalid or unsupported URL format.'], 400);
        }

        $existingBid = Bid::where('clean_job_id', $cleanId)->first();

        if ($existingBid) {
            return response()->json([
                'status' => 'collision',
                'message' => 'Another bidder in your agency has already bid on this job!',
                'bid' => $existingBid
            ]);
        }

        return response()->json(['status' => 'clear', 'message' => 'Safe to bid!', 'clean_job_id' => $cleanId]);
    }

    public function submit(Request $request, JobUrlParserService $parser)
    {
        $request->validate([
            'url' => 'required|url',
            'connects' => 'required|integer|min:1',
            'platform' => 'required|string',
            'job_title' => 'nullable|string|max:255',
        ]);

        $cleanId = $parser->extractCleanJobId($request->url);

        $bid = Bid::create([
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'job_url' => $request->url,
            'clean_job_id' => $cleanId,
            'platform_name' => $request->platform,
            'connects_used' => $request->connects,
            'job_title' => $request->job_title,
            'status' => 'Submitted'
        ]);

        return response()->json(['status' => 'success', 'bid' => $bid]);
    }

    public function myBids(Request $request)
    {
        $query = Bid::where('user_id', auth()->id())->orderBy('created_at', 'desc');

        if ($request->filled('filter')) {
            $filter = $request->filter;

            if ($filter === 'today') {
                $query->whereDate('created_at', today());
            } elseif ($filter === '7days') {
                $query->where('created_at', '>=', now()->subDays(7));
            } elseif ($filter === '30days') {
                $query->where('created_at', '>=', now()->subDays(30));
            } elseif ($filter === 'this_month') {
                $query->whereMonth('created_at', now()->month)
                      ->whereYear('created_at', now()->year);
            } elseif ($filter === 'custom' && $request->filled('from') && $request->filled('to')) {
                $from = Carbon::parse($request->from)->startOfDay();
                $to = Carbon::parse($request->to)->endOfDay();
                $query->whereBetween('created_at', [$from, $to]);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $bids = $query->paginate(20);

        $stats = [
            'total' => Bid::where('user_id', auth()->id())->count(),
            'today' => Bid::where('user_id', auth()->id())->whereDate('created_at', today())->count(),
            'this_week' => Bid::where('user_id', auth()->id())->where('created_at', '>=', now()->startOfWeek())->count(),
            'connects_today' => Bid::where('user_id', auth()->id())->whereDate('created_at', today())->sum('connects_used'),
        ];

        return response()->json([
            'bids' => $bids,
            'stats' => $stats,
        ]);
    }
}
