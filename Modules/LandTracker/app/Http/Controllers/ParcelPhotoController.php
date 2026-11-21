<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\ParcelPhoto;

class ParcelPhotoController extends Controller
{
    // Staff: add one or more photos to the end of the list
    public function store(Request $request, Parcel $parcel)
    {
        $max = (int) \App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($parcel))->get('limits.max_photos_per_parcel');
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:' . $max],
            'photos.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . config('platform.upload_kb.parcel_photo')],
        ]);

        $files = $request->file('photos');
        if ($parcel->photos()->count() + count($files) > $max) {
            return response()->json(['message' => 'A parcel can have at most ' . $max . ' photos.'], 422);
        }

        $stored = [];
        try {
            DB::transaction(function () use ($parcel, $files, &$stored) {
                $next = (int) $parcel->photos()->max('sort_order') + 1;
                foreach ($files as $file) {
                    $path = $file->store("parcel-photos/{$parcel->id}", \App\Support\Disks::uploads());
                    $stored[] = $path;
                    $parcel->photos()->create(['file_path' => $path, 'sort_order' => $next++]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk(\App\Support\Disks::uploads())->delete($stored); // no orphan files
            throw $e;
        }

        return response()->json($parcel->photos()->get(), 201);
    }

    // Staff: set the order by sending every photo id in the order wanted
    public function reorder(Request $request, Parcel $parcel)
    {
        $data = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['uuid', 'distinct']]);

        $current = $parcel->photos()->pluck('id')->all();
        if (count($data['ids']) !== count($current) || array_diff($current, $data['ids'])) {
            return response()->json(['message' => 'Send every photo of this parcel exactly once.'], 422);
        }

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $i => $id) {
                ParcelPhoto::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return $parcel->photos()->get();
    }

    public function destroy(ParcelPhoto $photo)
    {
        $path = $photo->file_path;
        $photo->delete();
        Storage::disk(\App\Support\Disks::uploads())->delete($path);

        return response()->noContent();
    }

    // Anyone, if the parcel's land is public; staff always
    public function file(Request $request, ParcelPhoto $photo)
    {
        $public = (bool) $photo->parcel?->land?->isListedPublicly();
        $user = $request->user('sanctum');
        $staff = $user?->isStaff() && \App\Support\Tenancy::allows($user, $photo);
        abort_unless($public || $staff, 404);
        abort_unless(Storage::disk(\App\Support\Disks::uploads())->exists($photo->file_path), 404);

        return Storage::disk(\App\Support\Disks::uploads())->response($photo->file_path, null, [
            'Cache-Control' => $public ? 'public, max-age=' . config('platform.cache_seconds.photos_and_logos') : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
