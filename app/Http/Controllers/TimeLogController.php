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

        $now = Carbon::now('UTC');

        $log = TimeLog::create([
            'tenant_id' => auth()->user()->tenant_id,
            'user_id' => auth()->id(),
            'login_time' => $now,
            'date' => $now->toDateString(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Punched in.',
            'punched_in_at' => $log->login_time->toIso8601String(),
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

        $now = Carbon::now('UTC');
        $totalHours = abs($now->floatDiffInHours($log->login_time));

        $log->update([
            'logout_time' => $now,
            'total_hours' => round($totalHours, 4),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Punched out.',
            'total_hours' => round($totalHours, 2),
        ]);
    }

    public function status()
    {
        $activeShift = TimeLog::where('user_id', auth()->id())
            ->whereNull('logout_time')
            ->latest('login_time')
            ->first();

        $now = Carbon::now('UTC');

        $todayLogs = TimeLog::where('user_id', auth()->id())
            ->where('date', $now->toDateString())
            ->get();

        $todayHours = 0.0;
        foreach ($todayLogs as $log) {
            if ($log->logout_time) {
                $todayHours += abs((float) $log->total_hours);
            } else {
                $todayHours += abs($now->floatDiffInHours($log->login_time));
            }
        }

        return response()->json([
            'is_punched_in' => (bool) $activeShift,
            'punched_in_at' => $activeShift?->login_time?->toIso8601String(),
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

        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'UTC')->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
        $today = Carbon::now('UTC')->startOfDay();

        $logs = TimeLog::where('user_id', auth()->id())
            ->whereBetween('date', [$startOfMonth->toDateString(), $startOfMonth->copy()->endOfMonth()->toDateString()])
            ->orderBy('login_time')
            ->get();

        $grouped = $logs->groupBy(fn ($log) => $log->date->format('Y-m-d'));

        $userCreatedAt = Carbon::parse(auth()->user()->created_at)->startOfDay();

        $days = [];
        $totalWorkedHours = 0.0;
        $presentDays = 0;
        $absentDays = 0;

        for ($d = $startOfMonth->copy(); $d->lte($startOfMonth->copy()->endOfMonth()); $d->addDay()) {
            $dateKey = $d->format('Y-m-d');
            $isWeekend = in_array($d->dayOfWeek, [0, 6]);
            $isFuture = $d->gt($today);
            $isBeforeJoin = $d->lt($userCreatedAt);

            $dayLogs = $grouped->get($dateKey, collect());
            $dayHours = 0.0;

            foreach ($dayLogs as $log) {
                if ($log->logout_time) {
                    $dayHours += abs((float) $log->total_hours);
                } elseif ($d->eq($today)) {
                    $dayHours += abs(Carbon::now('UTC')->floatDiffInHours($log->login_time));
                }
            }

            $dayHours = round($dayHours, 2);

            $sessions = $dayLogs->map(function ($l) {
                $hours = abs((float) $l->total_hours);
                if (!$l->logout_time) {
                    $hours = abs(round(Carbon::now('UTC')->floatDiffInHours($l->login_time), 4));
                }
                return [
                    'in' => $l->login_time?->toIso8601String(),
                    'out' => $l->logout_time?->toIso8601String(),
                    'hours' => round($hours, 2),
                ];
            })->values();

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
