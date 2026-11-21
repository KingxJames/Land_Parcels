<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\ParcelOutline;

// Staff only
class ParcelOutlineController extends Controller
{
    public function show(Parcel $parcel)
    {
        $outline = $parcel->outline()->with('editor:id,full_name')->firstOrFail();

        return $this->present($outline, $parcel);
    }

    // Create or replace the shape
    public function save(Request $request, Parcel $parcel)
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:3', 'max:500'],
            'points.*' => ['required', 'array:x,y'],
            'points.*.x' => ['required', 'numeric', 'between:0,100'],
            'points.*.y' => ['required', 'numeric', 'between:0,100'],
        ]);

        // Keep only x and y, as plain numbers rounded to 0.01%
        $points = array_map(fn ($p) => ['x' => round((float) $p['x'], 2), 'y' => round((float) $p['y'], 2)], $data['points']);

        if (ParcelOutline::area($points) < 0.0001) {
            return response()->json(['message' => 'The points don’t enclose any area.', 'errors' => ['points' => ['The points don’t enclose any area.']]], 422);
        }

        $before = $parcel->outline?->points;
        $outline = ParcelOutline::updateOrCreate(
            ['parcel_id' => $parcel->id],
            ['points' => $points, 'updated_by' => $request->user()->id],
        );

        \Modules\LandTracker\Services\Activity::record('outline_edited', $parcel, $request->user(), ['old' => ['points' => $before], 'attributes' => ['points' => $points]]);

        return response()->json($this->present($outline->load('editor:id,full_name'), $parcel), $outline->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Parcel $parcel)
    {
        \Modules\LandTracker\Services\Activity::record('outline_removed', $parcel, $request->user(), ['old' => ['points' => $parcel->outline?->points]]);
        $parcel->outline()->delete();

        return response()->noContent();
    }

    private function present(ParcelOutline $outline, Parcel $parcel): array
    {
        return [...$outline->toArray(), 'estimated_sqft' => $outline->estimatedSqft($parcel->land)];
    }
}
