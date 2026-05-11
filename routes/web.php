<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

Route::get('/welcome', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
})->name('welcome');

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
    Route::post('/api/bids/analyze-job', [\App\Http\Controllers\BidController::class, 'analyzeJob']);
    Route::get('/api/agency-profile', [\App\Http\Controllers\TenantAdminController::class, 'getAgencyProfile']);
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
        Route::get('/api/admin/agency-profile', [\App\Http\Controllers\TenantAdminController::class, 'getAgencyProfile']);
        Route::post('/api/admin/agency-profile', [\App\Http\Controllers\TenantAdminController::class, 'updateAgencyProfile']);
        Route::put('/api/admin/bids/{id}/status', [\App\Http\Controllers\TenantAdminController::class, 'updateBidStatus']);
        Route::put('/api/admin/notification-email', [\App\Http\Controllers\TenantAdminController::class, 'updateNotificationEmail']);

        // Positions
        Route::get('/api/admin/positions', [\App\Http\Controllers\PositionController::class, 'index']);
        Route::post('/api/admin/positions', [\App\Http\Controllers\PositionController::class, 'store']);
        Route::put('/api/admin/positions/{id}', [\App\Http\Controllers\PositionController::class, 'update']);
        Route::delete('/api/admin/positions/{id}', [\App\Http\Controllers\PositionController::class, 'destroy']);
        Route::post('/api/admin/positions/reorder', [\App\Http\Controllers\PositionController::class, 'reorder']);
    });

    // Stop impersonation (accessible while impersonating)
    Route::post('/api/admin/stop-impersonate', [\App\Http\Controllers\TenantAdminController::class, 'stopImpersonate']);

    // Pipeline access (TenantAdmin, SuperAdmin, Senior BDE)
    Route::middleware('can:manage-pipeline')->group(function () {
        Route::get('/api/pipeline/bids', [\App\Http\Controllers\TenantAdminController::class, 'efficiency']);
        Route::put('/api/pipeline/bids/{id}/status', [\App\Http\Controllers\TenantAdminController::class, 'updateBidStatus']);
    });

    // Messaging Routes
    Route::get('/api/messages/conversations', [\App\Http\Controllers\MessageController::class, 'conversations']);
    Route::get('/api/messages/unread-count', [\App\Http\Controllers\MessageController::class, 'unreadCount']);
    Route::get('/api/messages/search-users', [\App\Http\Controllers\MessageController::class, 'searchUsers']);
    Route::post('/api/messages/conversations/start', [\App\Http\Controllers\MessageController::class, 'startOrFind']);
    Route::get('/api/messages/conversations/{conversation}/messages', [\App\Http\Controllers\MessageController::class, 'messages']);
    Route::post('/api/messages/conversations/{conversation}/send', [\App\Http\Controllers\MessageController::class, 'send']);
    Route::post('/api/messages/conversations/{conversation}/read', [\App\Http\Controllers\MessageController::class, 'markRead']);
    Route::post('/api/messages/conversations/{conversation}/typing', [\App\Http\Controllers\MessageController::class, 'typing']);
    Route::get('/api/messages/attachments/{attachment}', [\App\Http\Controllers\MessageController::class, 'showAttachment']);
    Route::get('/api/messages/resolve/{hash}', function (string $hash) {
        $id = \App\Support\IdHash::decode($hash);
        if (!$id) return response()->json(['error' => 'Invalid link'], 404);
        return response()->json(['conversation_id' => $id]);
    })->where('hash', '[0-9a-zA-Z]{6}');

    // Profile API
    Route::get('/api/profile', [ProfileController::class, 'apiGet']);
    Route::post('/api/profile', [ProfileController::class, 'apiUpdate']);
    Route::get('/api/profile/picture/{token}', [ProfileController::class, 'showPicture'])->where('token', '[0-9a-zA-Z]{6}');
    Route::post('/api/profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('/api/profile/basic', [ProfileController::class, 'updateBasic']);

    // Presence heartbeat
    Route::post('/api/heartbeat', [\App\Http\Controllers\MessageController::class, 'heartbeat']);
    Route::post('/api/go-offline', [\App\Http\Controllers\MessageController::class, 'goOffline']);

    // Super Admin Routes
    Route::middleware('role:SuperAdmin')->group(function () {
        Route::get('/api/super/tenants', [\App\Http\Controllers\SuperAdminController::class, 'tenants']);
        Route::put('/api/super/tenants/{id}/status', [\App\Http\Controllers\SuperAdminController::class, 'updateStatus']);
    });
});

require __DIR__.'/auth.php';
