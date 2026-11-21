<?php

namespace Modules\Auth\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

// Staff routes: every record in the address must belong to the user's company.
// Another company's record answers "not found", the same as one that doesn't exist.
class EnsureSameOrg
{
    public function handle(Request $request, Closure $next)
    {
        foreach ($request->route()?->parameters() ?? [] as $value) {
            if ($value instanceof Model && ! Tenancy::allows($request->user(), $value)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
