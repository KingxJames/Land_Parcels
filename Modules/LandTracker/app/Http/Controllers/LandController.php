<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\LandTracker\Http\Requests\SaveLandRequest;
use Modules\LandTracker\Models\Land;

class LandController extends Controller
{
    // Public website: only lands marked public, in display order
    public function publicIndex(Request $request)
    {
        return Land::public()->ordered()
            ->when($request->query('org'), fn ($q, $slug) => $q->whereHas('organization', fn ($o) => $o->where('slug', $slug)))
            ->get(['id', 'name', 'place', 'site_plan_path', 'plan_width_ft', 'plan_height_ft', 'sort_order']);
    }

    public function publicShow(Land $land)
    {
        abort_unless($land->isListedPublicly(), 404);

        return $land;
    }

    // Staff: everything, optionally for one organization
    public function index(Request $request)
    {
        $request->validate(['org_id' => ['nullable', 'uuid']]);

        $mine = \App\Support\Tenancy::scope($request->user());

        return Land::with('organization:id,name')
            ->when($mine, fn ($q) => $q->where('org_id', $mine))
            ->when($request->org_id, fn ($q, $org) => $q->where('org_id', $org))
            ->ordered()
            ->paginate(\App\Support\Paging::size());
    }

    public function show(Land $land)
    {
        return $land->load('organization:id,name');
    }

    public function store(SaveLandRequest $request)
    {
        $land = new Land($request->safe()->except('site_plan'));
        $land->id = (string) \Illuminate\Support\Str::uuid();
        $this->attachSitePlan($request, $land);
        $land->save();
        \Modules\LandTracker\Services\Activity::record('land_created', $land, $request->user(), ['name' => $land->name]);

        return response()->json($land->load('organization:id,name'), 201);
    }

    // POST with _method=PATCH when sending a file, since PHP can't read files on real PATCH
    public function update(SaveLandRequest $request, Land $land)
    {
        $before = $land->getAttributes();
        $land->fill($request->safe()->except('site_plan'));
        $old = $this->attachSitePlan($request, $land);
        $land->save();
        if ($changes = \Modules\LandTracker\Services\Activity::changes($land, $before)) {
            \Modules\LandTracker\Services\Activity::record('land_updated', $land, $request->user(), $changes);
        }

        if ($old) {
            Storage::disk(\App\Support\Disks::uploads())->delete($old);
        }

        return $land->load('organization:id,name');
    }

    public function destroy(Request $request, Land $land)
    {
        if ($land->parcels()->exists()) {
            return response()->json(['message' => 'Remove this land’s parcels before deleting it.'], 409);
        }

        $path = $land->site_plan_path;
        \Modules\LandTracker\Services\Activity::record('land_deleted', $land, $request->user(), ['name' => $land->name]);
        $land->delete();

        if ($path) {
            Storage::disk(\App\Support\Disks::uploads())->delete($path);
        }

        return response()->noContent();
    }

    public function removeSitePlan(Land $land)
    {
        if ($land->site_plan_path) {
            Storage::disk(\App\Support\Disks::uploads())->delete($land->site_plan_path);
            $land->site_plan_path = null;
            $land->save();
        }

        return $land;
    }

    // Anyone may see a public land's plan; staff may see any
    public function sitePlan(Request $request, Land $land)
    {
        $user = $request->user('sanctum');
        $staff = $user?->isStaff() && \App\Support\Tenancy::allows($user, $land);
        abort_unless($land->isListedPublicly() || $staff, 404);
        abort_unless($land->site_plan_path && Storage::disk(\App\Support\Disks::uploads())->exists($land->site_plan_path), 404);

        return Storage::disk(\App\Support\Disks::uploads())->response($land->site_plan_path, null, [
            'Cache-Control' => $land->is_public ? 'public, max-age=' . config('platform.cache_seconds.site_plans_and_documents') : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // Stores a new upload and returns the old path so the caller can delete it after saving
    private function attachSitePlan(SaveLandRequest $request, Land $land): ?string
    {
        if (! $request->hasFile('site_plan')) {
            return null;
        }

        $old = $land->site_plan_path;
        $land->site_plan_path = $request->file('site_plan')->store("site-plans/{$land->id}", \App\Support\Disks::uploads());

        return $old;
    }
}
