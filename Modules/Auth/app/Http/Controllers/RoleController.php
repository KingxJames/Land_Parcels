<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\DefaultRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Services\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// users.manage: a company's roles and what each may do
class RoleController extends Controller
{
    // Super admin always has everything; Owner never has staff permissions
    public const LOCKED = ['super_admin', 'owner'];

    // The four every company starts with: can't be renamed or deleted
    public const BUILT_IN = ['super_admin', 'office_admin', 'cashier', 'owner'];

    public function index(Request $request)
    {
        $org = $this->org($request);

        $roles = Role::with('permissions:id,name')->where('team_id', $org->id)->orderBy('id')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'built_in' => in_array($r->name, self::BUILT_IN, true),
                'locked' => in_array($r->name, self::LOCKED, true),
                'permissions' => $r->permissions->pluck('name')->sort()->values(),
                'people' => DB::table('model_has_roles')->where('role_id', $r->id)->count(),
            ]);

        return ['roles' => $roles, 'all_permissions' => Permission::orderBy('name')->pluck('name')];
    }

    public function store(Request $request)
    {
        $org = $this->org($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::notIn(self::BUILT_IN),
                function ($attr, $value, $fail) use ($org) {
                    if (Role::where('team_id', $org->id)->whereRaw('lower(name) = lower(?)', [$value])->exists()) {
                        $fail('This company already has a role with that name.');
                    }
                }],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(DefaultRoles::PERMISSIONS)],
        ]);

        $role = Role::create(['name' => trim($data['name']), 'guard_name' => 'web', 'team_id' => $org->id]);
        $role->syncPermissions($data['permissions']);
        $this->forgetCache();
        // Roles have whole-number ids, so entries are filed under the company with the role named
        Activity::record('role_created', $org, $request->user(), ['role' => $role->name, 'permissions' => $data['permissions']], $org->id, $role->name);

        return response()->json($this->present($role), 201);
    }

    public function update(Request $request, Role $role)
    {
        if (in_array($role->name, self::LOCKED, true)) {
            throw ValidationException::withMessages(['role' => 'This role’s permissions are fixed.']);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:50', Rule::notIn(self::BUILT_IN),
                function ($attr, $value, $fail) use ($role) {
                    if (in_array($role->name, self::BUILT_IN, true)) {
                        $fail('Built-in roles can’t be renamed.');
                    } elseif (Role::where('team_id', $role->team_id)->where('id', '<>', $role->id)->whereRaw('lower(name) = lower(?)', [$value])->exists()) {
                        $fail('This company already has a role with that name.');
                    }
                }],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(DefaultRoles::PERMISSIONS)],
        ]);

        $before = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
        if (isset($data['name'])) {
            $role->update(['name' => trim($data['name'])]);
        }
        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }
        $this->forgetCache();

        $after = ['name' => $role->name, 'permissions' => $role->fresh('permissions')->permissions->pluck('name')->sort()->values()->all()];
        Activity::record('role_updated', Organization::find($role->team_id), $request->user(), ['role' => $before['name'], 'old' => $before, 'attributes' => $after], $role->team_id, $before['name']);

        return $this->present($role);
    }

    public function destroy(Request $request, Role $role)
    {
        if (in_array($role->name, self::BUILT_IN, true)) {
            throw ValidationException::withMessages(['role' => 'Built-in roles can’t be deleted.']);
        }
        if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
            return response()->json(['message' => 'Give the people in this role another role first.'], 409);
        }

        Activity::record('role_deleted', Organization::find($role->team_id), $request->user(), ['role' => $role->name], $role->team_id, $role->name);
        $role->delete();
        $this->forgetCache();

        return response()->noContent();
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id, 'name' => $role->name,
            'built_in' => in_array($role->name, self::BUILT_IN, true),
            'locked' => in_array($role->name, self::LOCKED, true),
            'permissions' => $role->fresh('permissions')->permissions->pluck('name')->sort()->values(),
        ];
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // Own company; the platform owner names one with ?org_id=
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
