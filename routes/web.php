<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    $user = auth()->user();
    if ($user->hasRole('SuperAdmin')) return Inertia::render('SuperAdminCommandCenter');
    if ($user->hasRole('TenantAdmin')) return Inertia::render('TenantAdminDashboard');
    return Inertia::render('BidderDashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Bidder Routes
    Route::post('/api/bids/check', [\App\Http\Controllers\BidController::class, 'checkCollision']);
    Route::post('/api/bids/submit', [\App\Http\Controllers\BidController::class, 'submit']);
    Route::get('/api/bids/mine', [\App\Http\Controllers\BidController::class, 'myBids']);
    Route::post('/api/time/punch-in', [\App\Http\Controllers\TimeLogController::class, 'punchIn']);
    Route::post('/api/time/punch-out', [\App\Http\Controllers\TimeLogController::class, 'punchOut']);
    Route::get('/api/time/status', [\App\Http\Controllers\TimeLogController::class, 'status']);
    Route::get('/api/time/attendance', [\App\Http\Controllers\TimeLogController::class, 'attendance']);

    // Tenant Admin Routes
    Route::middleware('role:TenantAdmin|SuperAdmin')->group(function () {
        Route::get('/api/admin/bidders', [\App\Http\Controllers\TenantAdminController::class, 'bidders']);
        Route::post('/api/admin/bidders', [\App\Http\Controllers\TenantAdminController::class, 'addMember']);
        Route::put('/api/admin/bidders/{id}', [\App\Http\Controllers\TenantAdminController::class, 'updateMember']);
        Route::get('/api/admin/bidders/{id}/profile', [\App\Http\Controllers\TenantAdminController::class, 'memberProfile']);
        Route::get('/api/admin/bidders/{id}/bid-report', [\App\Http\Controllers\TenantAdminController::class, 'memberBidReport']);
        Route::get('/api/admin/bidders/{id}/attendance', [\App\Http\Controllers\TenantAdminController::class, 'memberAttendance']);
        Route::put('/api/admin/bidders/{id}/attendance', [\App\Http\Controllers\TenantAdminController::class, 'updateDayStatus']);
        Route::delete('/api/admin/bidders/{id}/attendance', [\App\Http\Controllers\TenantAdminController::class, 'removeDayOverride']);
        Route::post('/api/admin/impersonate/{id}', [\App\Http\Controllers\TenantAdminController::class, 'impersonate']);
        Route::get('/api/admin/reports/efficiency', [\App\Http\Controllers\TenantAdminController::class, 'efficiency']);
        Route::put('/api/admin/bids/{id}/status', [\App\Http\Controllers\TenantAdminController::class, 'updateBidStatus']);
    });

    // Stop impersonation (accessible while impersonating)
    Route::post('/api/admin/stop-impersonate', [\App\Http\Controllers\TenantAdminController::class, 'stopImpersonate']);

    // Pipeline access (TenantAdmin, SuperAdmin, Senior BDE)
    Route::middleware('can:manage-pipeline')->group(function () {
        Route::get('/api/pipeline/bids', [\App\Http\Controllers\TenantAdminController::class, 'efficiency']);
        Route::put('/api/pipeline/bids/{id}/status', [\App\Http\Controllers\TenantAdminController::class, 'updateBidStatus']);
    });

    // Super Admin Routes
    Route::middleware('role:SuperAdmin')->group(function () {
        Route::get('/api/super/tenants', [\App\Http\Controllers\SuperAdminController::class, 'tenants']);
        Route::put('/api/super/tenants/{id}/status', [\App\Http\Controllers\SuperAdminController::class, 'updateStatus']);
    });
});

require __DIR__.'/auth.php';
