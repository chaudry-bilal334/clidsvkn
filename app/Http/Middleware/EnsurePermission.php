<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Guard a route behind a permission the authenticated user must hold.
     *
     * The owner role ("*") bypasses every check, and a "manage" permission is
     * treated as implying its "view" counterpart so Doctors who can manage
     * prescriptions can also read them through the shared permission model.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user() ?? Auth::user();

        $granted = $user?->permissions ?? [];

        if (in_array('*', $granted, true) || in_array($permission, $granted, true)) {
            return $next($request);
        }

        if (str_ends_with($permission, '.view')
            && in_array(substr($permission, 0, -strlen('.view')).'.manage', $granted, true)) {
            return $next($request);
        }

        abort(403, 'You are not authorized to perform this action.');
    }
}
