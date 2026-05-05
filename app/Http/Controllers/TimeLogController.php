<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TimeLog;
use Carbon\Carbon;

class TimeLogController extends Controller
{
    public function punchIn(Request $request)
    {
        $log = TimeLog::create([
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'login_time' => now(),
            'date' => today()
        ]);

        return response()->json(['status' => 'success', 'message' => 'Punched in.', 'log' => $log]);
    }

    public function punchOut(Request $request)
    {
        $log = TimeLog::where('user_id', auth()->id())
            ->whereNull('logout_time')
            ->latest('login_time')
            ->first();

        if (!$log) {
            return response()->json(['error' => 'No active shift found.'], 400);
        }

        $logoutTime = now();
        $totalHours = $logoutTime->diffInMinutes($log->login_time) / 60;

        $log->update([
            'logout_time' => $logoutTime,
            'total_hours' => $totalHours
        ]);

        return response()->json(['status' => 'success', 'message' => 'Punched out.', 'log' => $log]);
    }
}
