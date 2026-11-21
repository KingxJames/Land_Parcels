<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\LandTracker\Enums\ParcelKind;
use Modules\LandTracker\Enums\ParcelStatus;
use Modules\LandTracker\Http\Requests\SaveParcelRequest;
use Modules\LandTracker\Models\Land;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Services\ParcelSales;

class ParcelController extends Controller
{
    public function __construct(private ParcelSales $sales) {}

    // Public website: parcels of a public land, without owner or hold date
    public function publicIndex(Land $land)
    {
        abort_unless($land->isListedPublicly(), 404);

        return $land->parcels()->with(['outline', 'photos'])->ordered()->get()->map->toPublicArray();
    }

    // Public parcel page
    public function publicShow(Parcel $parcel)
    {
        abort_unless((bool) $parcel->land?->isListedPublicly(), 404);

        return $parcel->load(['outline', 'photos'])->toPublicArray();
    }

    // Signed-in owner: the parcels they hold or bought
    public function mine(Request $request)
    {
        return Parcel::with('land:id,name,place')
            ->where('owner_id', $request->user()->id)
            ->ordered()->get();
    }

    // Staff
    public function index(Request $request, Land $land)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ParcelStatus::class)],
            'kind' => ['nullable', Rule::enum(ParcelKind::class)],
        ]);

        return $land->parcels()->with(['owner:id,full_name,email', 'outline', 'photos'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->kind, fn ($q, $k) => $q->where('kind', $k))
            ->ordered()->get();
    }

    public function show(Parcel $parcel)
    {
        return $parcel->load(['land:id,name,place,plan_width_ft,plan_height_ft', 'owner:id,full_name,email', 'outline', 'photos']);
    }

    public function store(SaveParcelRequest $request, Land $land)
    {
        $parcel = $land->parcels()->create($request->validated());
        \Modules\LandTracker\Services\Activity::record('parcel_created', $parcel, $request->user(), ['label' => $parcel->label, 'price' => $parcel->price]);

        return response()->json($parcel->fresh(), 201);
    }

    public function update(SaveParcelRequest $request, Parcel $parcel)
    {
        $before = $parcel->getAttributes();
        $parcel->update($request->validated());
        $changes = \Modules\LandTracker\Services\Activity::changes($parcel, $before);
        if ($price = \Modules\LandTracker\Services\Activity::only($changes, 'price')) {
            \Modules\LandTracker\Services\Activity::record('price_changed', $parcel, $request->user(), $price);
            $changes = \Modules\LandTracker\Services\Activity::without($changes, 'price');
        }
        if ($changes) {
            \Modules\LandTracker\Services\Activity::record('parcel_updated', $parcel, $request->user(), $changes);
        }

        return $parcel->fresh(['owner:id,full_name,email']);
    }

    // Reserved or sold parcels are part of a sale record and stay
    public function destroy(Request $request, Parcel $parcel)
    {
        if ($parcel->status !== ParcelStatus::Available) {
            return response()->json(['message' => 'Only an available parcel can be deleted.'], 409);
        }

        // In a transaction so photo files are removed only if the delete succeeds
        \Illuminate\Support\Facades\DB::transaction(function () use ($parcel, $request) {
            \Modules\LandTracker\Services\Activity::record('parcel_deleted', $parcel, $request->user(), ['label' => $parcel->label]);
            $parcel->delete();
        });

        return response()->noContent();
    }

    public function reserve(Request $request, Parcel $parcel)
    {
        $data = $request->validate([
            'owner_id' => ['required', 'uuid', 'exists:users,id'],
            'hold_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . now()->addDays(\App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($parcel))->get('limits.max_hold_days'))->toDateString()],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ]);

        return $this->sales->reserve(
            $parcel, User::findOrFail($data['owner_id']), Carbon::parse($data['hold_until']),
            $request->user(), isset($data['price']) ? (string) $data['price'] : null,
        )->load('owner:id,full_name,email');
    }

    public function release(Request $request, Parcel $parcel)
    {
        return $this->sales->release($parcel, $request->user());
    }

    public function sell(Request $request, Parcel $parcel)
    {
        $data = $request->validate([
            'owner_id' => ['nullable', 'uuid', 'exists:users,id'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
        ]);

        return $this->sales->sell(
            $parcel, isset($data['owner_id']) ? User::findOrFail($data['owner_id']) : null,
            $request->user(), isset($data['price']) ? (string) $data['price'] : null,
        )->load('owner:id,full_name,email');
    }
}
