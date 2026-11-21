<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Company staff (any role except Owner) or the platform owner.
// Used on read-only staff screens; changes also need a specific permission.
class EnsureStaff
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isStaff()) {
            return response()->json(['message' => 'You don’t have access to this.'], 403);
        }

        return $next($request);
    }
}
