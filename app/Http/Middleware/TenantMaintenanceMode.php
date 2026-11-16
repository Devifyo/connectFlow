<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a tenant admin has taken their site down, members of that tenant get a maintenance page
 * (with the admin's reason) instead of the app. Admins, super admins and admins impersonating a
 * member keep full access so they can work and bring the site back up.
 */
class TenantMaintenanceMode
{
    /** Routes members may still reach while the site is down. */
    private const ALLOWED = ['logout', 'maintenance', 'api/maintenance/status', 'api/go-offline', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !$user->tenant_id) {
            return $next($request);
        }
        if (session()->has('impersonator_id') || $user->hasRole('TenantAdmin') || $user->hasRole('SuperAdmin')) {
            return $next($request);
        }

        $tenant = Tenant::find($user->tenant_id);
        $state = $tenant?->maintenanceState();
        if (!$state) {
            return $next($request);
        }

        foreach (self::ALLOWED as $path) {
            if ($request->is($path)) return $next($request);
        }

        // API / XHR calls: a 503 the frontend recognises and turns into a redirect.
        if (!$request->header('X-Inertia') && ($request->expectsJson() || $request->ajax())) {
            return response()->json(['maintenance' => true, ...$state], 503);
        }

        return Inertia::render('Maintenance', $state)->toResponse($request);
    }
}
