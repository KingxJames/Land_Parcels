<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Services\Activity;
use Spatie\Permission\Models\Role;

// users.manage: the people in a company (staff and owners) and their roles
class PeopleController extends Controller
{
    public function index(Request $request)
    {
        $org = $this->org($request);
        $request->validate(['role' => ['nullable', 'string', 'max:50'], 'status' => ['nullable', 'in:invited,active,switched_off']]);

        $people = User::where('org_id', $org->id)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->role, fn ($q, $r) => $q->whereIn('id', DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
                ->where('r.team_id', $org->id)->where('r.name', $r)->select('m.model_id')))
            ->orderBy('full_name')
            ->paginate(\App\Support\Paging::size());

        $people->getCollection()->transform(fn (User $u) => $this->present($u));

        return $people;
    }

    // Invite someone: they sign in with Google using this email
    public function store(Request $request)
    {
        $org = $this->org($request);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255', function ($attr, $value, $fail) use ($org) {
                if (User::where('org_id', $org->id)->where('email', strtolower($value))->exists()) {
                    $fail('Someone in this company already uses that email.');
                }
            }],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'string', $this->roleExists($org)],
        ]);

        $user = DB::transaction(function () use ($org, $data) {
            $user = User::create([...collect($data)->except('role')->all(), 'org_id' => $org->id, 'status' => 'invited']);
            DefaultRoles::assign($user, $data['role']);

            return $user;
        });
        Activity::record('person_invited', $user, $request->user(), ['role' => $data['role']]);

        return response()->json($this->present($user), 201);
    }

    public function update(Request $request, User $user)
    {
        $org = $user->organization;
        abort_unless($org, 404);

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:active,switched_off'],
            'role' => ['sometimes', 'string', $this->roleExists($org)],
        ]);

        $self = $request->user()->id === $user->id;
        if ($self && (isset($data['role']) || ($data['status'] ?? null) === 'switched_off')) {
            throw ValidationException::withMessages(['user' => 'You can’t change your own role or switch yourself off.']);
        }

        // A company must always keep one Super admin who can sign in
        $losingSuperAdmin = $user->hasCompanyRole('super_admin')
            && ((isset($data['role']) && $data['role'] !== 'super_admin') || ($data['status'] ?? null) === 'switched_off');
        if ($losingSuperAdmin && $this->activeSuperAdmins($org) <= 1) {
            throw ValidationException::withMessages(['user' => 'This is the company’s last Super admin.']);
        }

        $before = $user->only(['full_name', 'phone', 'status']) + ['role' => $user->companyRoleNames()[0] ?? null];
        $user->update(collect($data)->except('role')->all());
        if (isset($data['role'])) {
            DefaultRoles::assign($user, $data['role']);
        }
        $after = $user->only(['full_name', 'phone', 'status']) + ['role' => $user->companyRoleNames()[0] ?? null];

        Activity::record(isset($data['role']) ? 'person_role_changed' : 'person_updated', $user, $request->user(),
            ['old' => array_diff_assoc($before, $after), 'attributes' => array_diff_assoc($after, $before)]);

        return $this->present($user->fresh());
    }

    private function present(User $u): array
    {
        return [...$u->only(['id', 'full_name', 'email', 'phone', 'status', 'last_sign_in_at']), 'roles' => $u->companyRoleNames()];
    }

    private function roleExists(Organization $org): \Closure
    {
        return function ($attr, $value, $fail) use ($org) {
            if (! Role::where('team_id', $org->id)->where('name', $value)->exists()) {
                $fail('This company has no role with that name.');
            }
        };
    }

    private function activeSuperAdmins(Organization $org): int
    {
        return DB::table('model_has_roles as m')->join('roles as r', 'r.id', '=', 'm.role_id')
            ->join('users as u', 'u.id', '=', 'm.model_id')
            ->where('r.team_id', $org->id)->where('r.name', 'super_admin')->where('u.status', '<>', 'switched_off')
            ->count();
    }

    private function org(Request $request): Organization
    {
        $user = $request->user();
        $org = $user->isPlatformAdmin() && $request->filled('org_id')
            ? Organization::find($request->input('org_id'))
            : $user->organization;
        abort_unless($org, 404);

        return $org;
    }
}
