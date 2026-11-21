<?php

namespace App\Http\Controllers;

use App\Models\TimeLog;
use App\Services\ActivityCheckService;
use Illuminate\Http\Request;

class ActivityCheckController extends Controller
{
    /**
     * Sent by the member app every 30 seconds while punched in. Carries how long ago the last real
     * input (touch / keyboard / mouse / scroll) happened; nothing is shown to the member.
     */
    public function ping(Request $request, ActivityCheckService $service)
    {
        $request->validate(['idle_seconds' => 'nullable|integer|min:0|max:86400']);
        $user = auth()->user();
        $log = TimeLog::where('user_id', $user->id)->whereNull('logout_time')->latest('login_time')->first();
        if (!$log) return response()->json(['punched_in' => false]);

        if (!session()->has('impersonator_id') && $log->activity_checks && $request->filled('idle_seconds')) {
            $service->recordActivity($log, now()->subSeconds((int) $request->idle_seconds));
        }
        return response()->json(['punched_in' => true]);
    }
}
