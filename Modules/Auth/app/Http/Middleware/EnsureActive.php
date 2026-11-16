<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Runs on every API request: a signed-in user whose account isn't active
// (e.g. switched off by the office) loses their token and gets a 401.
class EnsureActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('sanctum')->user();

        if ($user && $user->status !== 'active') {
            $user->tokens()->delete();

            return response()->json(['message' => 'This account has been switched off. Please contact the office.'], 401);
        }

        return $next($request);
    }
}
