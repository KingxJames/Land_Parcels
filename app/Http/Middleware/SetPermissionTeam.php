<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Roles and permissions are per company: look them up in the signed-in user's company.
// The platform owner has no company, so only platform-wide roles apply to them.
class SetPermissionTeam
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = Auth::guard('sanctum')->user()) {
            setPermissionsTeamId($user->org_id);
        }

        return $next($request);
    }
}
