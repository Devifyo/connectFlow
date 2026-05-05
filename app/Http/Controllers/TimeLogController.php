<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TimeLog;
use Carbon\Carbon;

class TimeLogController extends Controller
{
    public function punchIn(Request $request)
    {
        $activeShift = TimeLog::where('user_id', auth()->id())
            ->whereNull('logout_time')
            ->first();

        if ($activeShift) {
            return response()->json(['error' => 'Already punched in.'], 400);
        }

        $log = TimeLog::create([
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'login_time' => now(),
            'date' => today()
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Punched in.',
            'log' => $log,
            'punched_in_at' => $log->login_time,
        ]);
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
            'total_hours' => round($totalHours, 2)
        ]);

        return response()->json(['status' => 'success', 'message' => 'Punched out.', 'log' => $log]);
    }

    public function status()
    {
        $activeShift = TimeLog::where('user_id', auth()->id())
            ->whereNull('logout_time')
            ->latest('login_time')
            ->first();

        $todayLogs = TimeLog::where('user_id', auth()->id())
            ->where('date', today())
            ->get();

        $todayHours = $todayLogs->sum('total_hours');

        if ($activeShift) {
            $todayHours += now()->diffInMinutes($activeShift->login_time) / 60;
        }

        return response()->json([
            'is_punched_in' => (bool) $activeShift,
            'punched_in_at' => $activeShift?->login_time,
            'today_hours' => round($todayHours, 2),
            'today_sessions' => $todayLogs->count(),
        ]);
    }
}
