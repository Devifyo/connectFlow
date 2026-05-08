<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Bid;
use App\Models\TimeLog;
use App\Models\AttendanceOverride;
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
                    'employee_id' => $bidder->employee_id,
                    'designation' => $bidder->designation,
                    'is_active' => $bidder->is_active,
                    'joining_date' => $bidder->joining_date,
                    'salary' => $bidder->salary ? (float) $bidder->salary : null,
                    'min_hours_per_day' => (float) $bidder->min_hours_per_day,
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

    public function addMember(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'designation' => 'required|in:Intern BDE,BDE Bidder,Senior BDE',
            'joining_date' => 'required|date',
            'salary' => 'required|numeric|min:0',
            'min_hours_per_day' => 'required|numeric|min:1|max:24',
        ]);

        $tenantId = auth()->user()->tenant_id;
        $lastEmp = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('employee_id')
            ->orderByRaw('CAST(SUBSTRING(employee_id, 4) AS UNSIGNED) DESC')
            ->first();

        $nextNum = 1;
        if ($lastEmp && preg_match('/^CF-(\d+)$/', $lastEmp->employee_id, $m)) {
            $nextNum = (int) $m[1] + 1;
        }
        $employeeId = 'CF-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'tenant_id' => $tenantId,
            'is_active' => true,
            'designation' => $request->designation,
            'employee_id' => $employeeId,
            'joining_date' => $request->joining_date,
            'salary' => $request->salary,
            'min_hours_per_day' => $request->min_hours_per_day,
        ]);
        $user->assignRole('Bidder');

        return response()->json([
            'status' => 'success',
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'designation' => $user->designation,
            ],
        ]);
    }

    public function updateMember(Request $request, $id)
    {
        $request->validate([
            'designation' => 'sometimes|in:Intern BDE,BDE Bidder,Senior BDE',
            'is_active' => 'sometimes|boolean',
            'salary' => 'sometimes|numeric|min:0',
            'min_hours_per_day' => 'sometimes|numeric|min:1|max:24',
        ]);

        $user = User::findOrFail($id);

        $fields = ['designation', 'is_active', 'salary', 'min_hours_per_day'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $user->$field = $request->$field;
            }
        }
        $user->save();

        return response()->json(['status' => 'success']);
    }

    public function impersonate(Request $request, $id)
    {
        $target = User::findOrFail($id);

        if ($target->tenant_id !== auth()->user()->tenant_id) {
            return response()->json(['error' => 'Cannot impersonate users from other tenants.'], 403);
        }

        if ($target->id === auth()->id()) {
            return response()->json(['error' => 'Cannot impersonate yourself.'], 400);
        }

        session()->put('impersonator_id', auth()->id());
        auth()->login($target);

        return response()->json(['status' => 'success', 'redirect' => '/dashboard']);
    }

    public function stopImpersonate(Request $request)
    {
        $impersonatorId = session()->get('impersonator_id');

        if (!$impersonatorId) {
            return response()->json(['error' => 'Not impersonating.'], 400);
        }

        $admin = User::withoutGlobalScopes()->findOrFail($impersonatorId);
        session()->forget('impersonator_id');
        auth()->login($admin);

        return response()->json(['status' => 'success', 'redirect' => '/dashboard']);
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

    public function memberProfile($id)
    {
        $user = User::findOrFail($id);

        return response()->json([
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'designation' => $user->designation,
                'joining_date' => $user->joining_date,
                'salary' => $user->salary ? (float) $user->salary : null,
                'min_hours_per_day' => (float) $user->min_hours_per_day,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function memberBidReport(Request $request, $id)
    {
        $request->validate([
            'filter' => 'nullable|in:today,week,month,range',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $user = User::findOrFail($id);
        $now = Carbon::now('UTC');
        $filter = $request->filter ?? 'month';

        $query = Bid::where('user_id', $user->id);

        if ($filter === 'today') {
            $query->whereDate('created_at', $now->toDateString());
        } elseif ($filter === 'week') {
            $query->where('created_at', '>=', $now->copy()->startOfWeek());
        } elseif ($filter === 'month') {
            $query->where('created_at', '>=', $now->copy()->startOfMonth());
        } elseif ($filter === 'range' && $request->from && $request->to) {
            $query->whereBetween('created_at', [
                Carbon::parse($request->from)->startOfDay(),
                Carbon::parse($request->to)->endOfDay(),
            ]);
        }

        $bids = $query->orderBy('created_at', 'desc')->get();

        $statusCounts = $bids->groupBy('status')->map->count();
        $total = $bids->count();

        return response()->json([
            'filter' => $filter,
            'total' => $total,
            'status_counts' => [
                'Submitted' => $statusCounts->get('Submitted', 0),
                'Interviewing' => $statusCounts->get('Interviewing', 0),
                'Hired' => $statusCounts->get('Hired', 0),
                'Rejected' => $statusCounts->get('Rejected', 0),
            ],
            'bids' => $bids->map(fn ($b) => [
                'bid_id' => $b->bid_id,
                'job_title' => $b->job_title,
                'job_url' => $b->job_url,
                'platform_name' => $b->platform_name,
                'connects_used' => $b->connects_used,
                'status' => $b->status,
                'created_at' => $b->created_at->toIso8601String(),
            ]),
        ]);
    }

    public function memberAttendance(Request $request, $id)
    {
        $request->validate([
            'year' => 'nullable|integer|min:2020|max:2099',
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $user = User::findOrFail($id);

        $year = (int) ($request->year ?? now()->year);
        $month = (int) ($request->month ?? now()->month);

        $startOfMonth = Carbon::createFromDate($year, $month, 1, 'UTC')->startOfDay();
        $today = Carbon::now('UTC')->startOfDay();

        $logs = TimeLog::where('user_id', $user->id)
            ->whereBetween('date', [$startOfMonth->toDateString(), $startOfMonth->copy()->endOfMonth()->toDateString()])
            ->orderBy('login_time')
            ->get();

        $overrides = AttendanceOverride::where('user_id', $user->id)
            ->whereBetween('date', [$startOfMonth->toDateString(), $startOfMonth->copy()->endOfMonth()->toDateString()])
            ->get()
            ->keyBy(fn ($o) => $o->date->format('Y-m-d'));

        $grouped = $logs->groupBy(fn ($log) => $log->date->format('Y-m-d'));
        $userCreatedAt = Carbon::parse($user->created_at)->startOfDay();

        $days = [];
        $totalWorkedHours = 0.0;
        $presentDays = 0;
        $absentDays = 0;

        for ($d = $startOfMonth->copy(); $d->lte($startOfMonth->copy()->endOfMonth()); $d->addDay()) {
            $dateKey = $d->format('Y-m-d');
            $isWeekend = in_array($d->dayOfWeek, [0, 6]);
            $isFuture = $d->gt($today);
            $isBeforeJoin = $d->lt($userCreatedAt);

            $override = $overrides->get($dateKey);
            $dayLogs = $grouped->get($dateKey, collect());
            $dayHours = 0.0;

            foreach ($dayLogs as $log) {
                if ($log->logout_time) {
                    $dayHours += abs((float) $log->total_hours);
                } elseif ($d->eq($today)) {
                    $dayHours += abs(Carbon::now('UTC')->floatDiffInHours($log->login_time));
                }
            }

            if ($override && $override->manual_hours !== null) {
                $dayHours = (float) $override->manual_hours;
            }

            $dayHours = round($dayHours, 2);

            $sessions = $dayLogs->map(function ($l) {
                $hours = abs((float) $l->total_hours);
                if (!$l->logout_time) {
                    $hours = abs(round(Carbon::now('UTC')->floatDiffInHours($l->login_time), 4));
                }
                return [
                    'log_id' => $l->log_id,
                    'in' => $l->login_time?->toIso8601String(),
                    'out' => $l->logout_time?->toIso8601String(),
                    'hours' => round($hours, 2),
                    'in_location' => $l->punch_in_lat ? ['lat' => (float) $l->punch_in_lat, 'lng' => (float) $l->punch_in_lng, 'address' => $l->punch_in_address] : null,
                    'out_location' => $l->punch_out_lat ? ['lat' => (float) $l->punch_out_lat, 'lng' => (float) $l->punch_out_lng, 'address' => $l->punch_out_address] : null,
                ];
            })->values();

            if ($override) {
                $status = $override->status;
                if ($status === 'present') { $presentDays++; $totalWorkedHours += $dayHours; }
                elseif ($status === 'absent') { $absentDays++; }
            } elseif ($isBeforeJoin) {
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
                'override' => $override ? ['status' => $override->status, 'hours' => $override->manual_hours, 'note' => $override->note] : null,
            ];
        }

        return response()->json([
            'member' => ['id' => $user->id, 'name' => $user->name, 'employee_id' => $user->employee_id],
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

    public function updateDayStatus(Request $request, $userId)
    {
        $request->validate([
            'date' => 'required|date',
            'status' => 'required|in:present,absent,week_off,half_day',
            'manual_hours' => 'nullable|numeric|min:0|max:24',
            'note' => 'nullable|string|max:255',
        ]);

        $user = User::findOrFail($userId);

        AttendanceOverride::updateOrCreate(
            ['user_id' => $user->id, 'date' => $request->date],
            [
                'tenant_id' => auth()->user()->tenant_id,
                'status' => $request->status,
                'manual_hours' => $request->manual_hours,
                'note' => $request->note,
                'marked_by' => auth()->id(),
            ]
        );

        return response()->json(['status' => 'success']);
    }

    public function removeDayOverride(Request $request, $userId)
    {
        $request->validate(['date' => 'required|date']);

        AttendanceOverride::where('user_id', $userId)
            ->where('date', $request->date)
            ->delete();

        return response()->json(['status' => 'success']);
    }
}
