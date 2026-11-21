<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// Only you, the platform owner
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isPlatformAdmin()) {
            return response()->json(['message' => 'You don’t have access to this.'], 403);
        }

        return $next($request);
    }
}
