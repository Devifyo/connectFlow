<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Bid;
use App\Services\JobUrlParserService;

class BidController extends Controller
{
    public function checkCollision(Request $request, JobUrlParserService $parser)
    {
        $request->validate(['url' => 'required|url']);
        
        $cleanId = $parser->extractCleanJobId($request->url);
        
        if (!$cleanId) {
            return response()->json(['error' => 'Invalid or unsupported URL format.'], 400);
        }

        // TenantScope automatically scopes this to the current tenant.
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
            'connects' => 'required|integer',
            'platform' => 'required|string'
        ]);

        $cleanId = $parser->extractCleanJobId($request->url);

        $bid = Bid::create([
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'job_url' => $request->url,
            'clean_job_id' => $cleanId,
            'platform_name' => $request->platform,
            'connects_used' => $request->connects,
            'status' => 'Submitted'
        ]);

        return response()->json(['status' => 'success', 'bid' => $bid]);
    }
}
