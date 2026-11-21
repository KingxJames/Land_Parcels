<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

// Staff: read-only, own company only
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'org_id' => ['nullable', 'uuid'],
            'log_name' => ['nullable', 'string', 'max:50'],
            'subject_type' => ['nullable', 'string', 'max:50'],
            'subject_id' => ['nullable', 'uuid'],
            'event' => ['nullable', 'in:created,updated,deleted'],
            'action' => ['nullable', 'string', 'max:50'],
            'causer_id' => ['nullable', 'uuid'],
        ]);
        $org = \App\Support\Tenancy::scope($request->user());

        return Activity::with('causer:id,full_name')
            ->when($org, fn ($q) => $q->where('org_id', $org))
            ->when($request->org_id, fn ($q, $v) => $q->where('org_id', $v))
            ->when($request->log_name, fn ($q, $v) => $q->where('log_name', $v))
            ->when($request->subject_type, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->subject_id, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->event, fn ($q, $v) => $q->where('event', $v))
            ->when($request->action, fn ($q, $v) => $q->where('properties->action', $v))
            ->when($request->causer_id, fn ($q, $v) => $q->where('causer_id', $v))
            ->orderByDesc('id')
            ->paginate(\App\Support\Paging::size());
    }
}
