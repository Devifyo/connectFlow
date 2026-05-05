<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;

class SuperAdminController extends Controller
{
    public function tenants()
    {
        // SuperAdmin can see all tenants (no scope applied to Tenant model)
        $tenants = Tenant::all();
        return response()->json(['tenants' => $tenants]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string']);
        
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['subscription_status' => $request->status]);

        return response()->json(['status' => 'success', 'tenant' => $tenant]);
    }
}
