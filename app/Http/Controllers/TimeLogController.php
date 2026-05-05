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

    public function attendance(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer|min:2020|max:2099',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $year = (int) ($request->year ?? now()->year);
        $month = (int) ($request->month ?? now()->month);

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();
        $today = now()->startOfDay();

        $logs = TimeLog::where('user_id', auth()->id())
            ->whereBetween('date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->orderBy('login_time')
            ->get();

        $grouped = $logs->groupBy(fn ($log) => Carbon::parse($log->date)->format('Y-m-d'));

        $userCreatedAt = Carbon::parse(auth()->user()->created_at)->startOfDay();

        $days = [];
        $totalWorkedHours = 0;
        $presentDays = 0;
        $absentDays = 0;

        for ($d = $startOfMonth->copy(); $d->lte($endOfMonth); $d->addDay()) {
            $dateKey = $d->format('Y-m-d');
            $dayOfWeek = $d->dayOfWeek; // 0=Sun, 6=Sat
            $isWeekend = in_array($dayOfWeek, [0, 6]);
            $isFuture = $d->gt($today);
            $isBeforeJoin = $d->lt($userCreatedAt);

            $dayLogs = $grouped->get($dateKey, collect());
            $dayHours = $dayLogs->sum('total_hours');

            $activeOnThisDay = null;
            if ($d->eq($today)) {
                $active = TimeLog::where('user_id', auth()->id())
                    ->whereNull('logout_time')
                    ->where('date', $dateKey)
                    ->first();
                if ($active) {
                    $dayHours += now()->diffInMinutes($active->login_time) / 60;
                }
            }

            $dayHours = round($dayHours, 2);

            $sessions = $dayLogs->map(fn ($l) => [
                'in' => $l->login_time ? Carbon::parse($l->login_time)->format('h:i A') : null,
                'out' => $l->logout_time ? Carbon::parse($l->logout_time)->format('h:i A') : null,
                'hours' => round($l->total_hours, 2),
            ])->values();

            $status = 'future';
            if ($isBeforeJoin) {
                $status = 'na';
            } elseif ($isFuture) {
                $status = 'future';
            } elseif ($dayLogs->count() > 0 || $dayHours > 0) {
                $status = 'present';
                $presentDays++;
                $totalWorkedHours += $dayHours;
            } elseif ($isWeekend) {
                $status = 'weekend';
            } else {
                $status = 'absent';
                $absentDays++;
            }

            $days[] = [
                'date' => $dateKey,
                'day' => (int) $d->format('d'),
                'day_name' => $d->format('D'),
                'status' => $status,
                'hours' => $dayHours,
                'sessions' => $sessions,
            ];
        }

        return response()->json([
            'year' => $year,
            'month' => $month,
            'month_name' => $startOfMonth->format('F'),
            'days' => $days,
            'summary' => [
                'total_worked_hours' => round($totalWorkedHours, 2),
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'avg_hours_per_day' => $presentDays > 0 ? round($totalWorkedHours / $presentDays, 2) : 0,
            ],
        ]);
    }
}
