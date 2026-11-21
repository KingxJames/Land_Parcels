<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\LandTracker\Http\Requests\SaveBuyerProfileRequest;
use Modules\LandTracker\Models\BuyerProfile;

// Every route here sits behind role:super_admin,office_admin
class BuyerProfileController extends Controller
{
    public function index(Request $request)
    {
        $org = \App\Support\Tenancy::scope($request->user());
        $query = BuyerProfile::with('user:id,full_name,email')->latest('updated_at')
            ->when($org, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('org_id', $org)));

        if ($request->filled('verified')) {
            $request->boolean('verified') ? $query->whereNotNull('verified_at') : $query->whereNull('verified_at');
        }

        return $query->paginate(\App\Support\Paging::size());
    }

    public function show(Request $request, User $user)
    {
        $profile = BuyerProfile::with(['user:id,full_name,email', 'verifier:id,full_name'])->findOrFail($user->id);
        $this->logAccess($request, $profile, 'viewed');

        return $profile;
    }

    // Create or replace the profile for one user
    public function save(SaveBuyerProfileRequest $request, User $user)
    {
        $profile = BuyerProfile::firstOrNew(['user_id' => $user->id]);
        $data = $request->safe()->only(['id_type', 'home_address', 'source_of_funds']);

        if ($request->has('id_number')) {
            $data['id_number_encrypted'] = $request->input('id_number');
        }

        $oldFile = null;
        if ($request->hasFile('id_file')) {
            $oldFile = $profile->id_file_path;
            $data['id_file_path'] = $request->file('id_file')->store("buyers/{$user->id}", \App\Support\Disks::idScans());
        }

        $profile->fill($data)->save();

        if ($oldFile) {
            Storage::disk(\App\Support\Disks::idScans())->delete($oldFile);
        }

        $this->logAccess($request, $profile, 'saved');

        return response()->json($profile->fresh(['user:id,full_name,email']), $profile->wasRecentlyCreated ? 201 : 200);
    }

    // Staff confirm they checked the ID against the person
    public function verify(Request $request, User $user)
    {
        $profile = BuyerProfile::findOrFail($user->id);

        if (! $profile->id_type || ! $profile->id_number_encrypted || ! $profile->id_file_path) {
            return response()->json(['message' => 'Add the ID type, number and scan before verifying.'], 422);
        }

        if ($profile->user_id === $request->user()->id) {
            return response()->json(['message' => 'You can’t verify your own profile.'], 403);
        }

        $profile->verified_by = $request->user()->id;
        $profile->verified_at = now();
        $profile->save();

        $this->logAccess($request, $profile, 'verified');

        return $profile->fresh(['verifier:id,full_name']);
    }

    public function idFile(Request $request, User $user)
    {
        $profile = BuyerProfile::findOrFail($user->id);

        abort_unless($profile->id_file_path && Storage::disk(\App\Support\Disks::idScans())->exists($profile->id_file_path), 404);

        $this->logAccess($request, $profile, 'downloaded_id');

        return Storage::disk(\App\Support\Disks::idScans())->download($profile->id_file_path);
    }

    // Who looked at whose private details, and when
    private function logAccess(Request $request, BuyerProfile $profile, string $action): void
    {
        Log::channel(config('logging.kyc_channel', config('logging.default')))->info('buyer_profile.' . $action, [
            'buyer_id' => $profile->user_id,
            'staff_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);
    }
}
