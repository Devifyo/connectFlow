<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Bid;

class TenantAdminController extends Controller
{
    public function bidders()
    {
        // Scoped to tenant via TenantScope
        $bidders = User::role('Bidder')->get();
        return response()->json(['bidders' => $bidders]);
    }

    public function efficiency()
    {
        $bids = Bid::with('user')->get();
        return response()->json(['bids' => $bids]);
    }

    public function updateBidStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string']);
        
        $bid = Bid::findOrFail($id);
        $bid->update(['status' => $request->status]);

        return response()->json(['status' => 'success', 'bid' => $bid]);
    }
}
