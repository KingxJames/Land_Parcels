<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\LandTracker\Services\Activity;

class OrganizationController extends Controller
{
    private const PUBLIC_FIELDS = ['name', 'slug', 'currency', 'theme', 'phone', 'whatsapp', 'email', 'address'];

    // Public site: a company's name, contacts and branding
    public function publicShow(Organization $org)
    {
        abort_if($org->isSuspended(), 404);

        return [...$org->only(self::PUBLIC_FIELDS), 'logo_url' => $org->logo_path ? url("/api/v1/public/orgs/{$org->slug}/logo") : null];
    }

    public function logo(Organization $org)
    {
        abort_if($org->isSuspended() || ! $org->logo_path || ! Storage::disk(\App\Support\Disks::uploads())->exists($org->logo_path), 404);

        return Storage::disk(\App\Support\Disks::uploads())->response($org->logo_path, null, [
            'Cache-Control' => 'public, max-age=' . config('platform.cache_seconds.photos_and_logos'), 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ---- Company staff: their own company ----

    public function mine(Request $request)
    {
        return $this->ownOrg($request);
    }

    // Company super admins edit their profile; status and slug are the platform owner's
    public function updateMine(Request $request)
    {
        $org = $this->ownOrg($request);
        $data = $request->validate($this->rules($org, false));

        return $this->save($request, $org, $data);
    }

    public function uploadLogo(Request $request)
    {
        $org = $request->user()->isPlatformAdmin() && $request->filled('org_id')
            ? Organization::findOrFail($request->input('org_id'))
            : $this->ownOrg($request);

        $request->validate(['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . config('platform.upload_kb.logo')]]);
        $old = $org->logo_path;
        $org->logo_path = $request->file('logo')->store("logos/{$org->id}", \App\Support\Disks::uploads());
        $org->save();
        if ($old) {
            Storage::disk(\App\Support\Disks::uploads())->delete($old);
        }
        Activity::record('logo_changed', $org, $request->user());

        return $org;
    }

    // ---- Platform owner ----

    public function index()
    {
        return Organization::withCount(['users', 'lands'])->orderBy('name')->get();
    }

    // New company, optionally inviting its first admin in the same step
    public function store(Request $request)
    {
        $data = $request->validate([
            ...$this->rules(null, true),
            'name' => ['required', 'string', 'max:150'],
            'admin_email' => ['nullable', 'email:rfc', 'max:255'],
            'admin_name' => ['required_with:admin_email', 'nullable', 'string', 'max:150'],
        ]);

        $org = DB::transaction(function () use ($data, $request) {
            $org = new Organization(collect($data)->except(['status', 'admin_email', 'admin_name'])->all());
            $org->status = $data['status'] ?? 'trial';
            $org->save();

            if (! empty($data['admin_email'])) {
                $admin = User::create([
                    'org_id' => $org->id, 'full_name' => $data['admin_name'], 'email' => $data['admin_email'], 'status' => 'invited',
                ]);
                \App\Support\DefaultRoles::assign($admin, 'super_admin');
            }
            Activity::record('organization_created', $org, $request->user(), ['name' => $org->name]);

            return $org;
        });

        return response()->json($org->fresh()->loadCount(['users', 'lands']), 201);
    }

    public function update(Request $request, Organization $org)
    {
        return $this->save($request, $org, $request->validate($this->rules($org, true)));
    }

    // ---- shared ----

    private function rules(?Organization $org, bool $platform): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'max:150'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'theme' => ['sometimes', 'string', 'max:40'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Ends in a letter or "-" so it can't run into the number: "R-" or "CR" are fine, "R1" isn't
            'receipt_prefix' => ['sometimes', 'string', 'regex:/^[A-Z0-9]{0,7}[A-Z-]$/', Rule::unique('organizations')->ignore($org?->id)],
            'reminder_days_before' => ['sometimes', 'integer', 'between:0,60'],
        ];
        if ($platform) {
            $rules['slug'] = ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('organizations')->ignore($org?->id)];
            $rules['status'] = ['sometimes', Rule::in(Organization::STATUSES)];
        }

        return $rules;
    }

    private function save(Request $request, Organization $org, array $data): Organization
    {
        $before = $org->getAttributes();
        $org->fill(collect($data)->except('status')->all());
        if (array_key_exists('status', $data)) {
            $org->status = $data['status'];
        }
        $org->save();

        if ($changes = Activity::changes($org, $before)) {
            Activity::record(isset($changes['attributes']['status']) ? 'organization_status_changed' : 'organization_updated', $org, $request->user(), $changes);
        }

        return $org;
    }

    private function ownOrg(Request $request): Organization
    {
        $org = $request->user()->organization;
        abort_unless($org, 404);

        return $org;
    }
}
