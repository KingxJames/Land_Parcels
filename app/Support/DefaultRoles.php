<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// The permission list, shared by every company, and the four roles each company starts with.
// A company's Super admin can add more roles later.
class DefaultRoles
{
    public const PERMISSIONS = [
        'lands.manage', 'parcels.manage', 'parcels.sell', 'plans.manage',
        'payments.record', 'payments.confirm', 'receipts.send', 'reminders.send',
        'buyers.verify', 'enquiries.manage', 'documents.manage',
        'connections.manage', 'activity.view', 'org.manage', 'users.manage',
    ];

    public const ROLES = [
        'super_admin' => self::PERMISSIONS,
        'office_admin' => [
            'lands.manage', 'parcels.manage', 'parcels.sell', 'plans.manage',
            'payments.record', 'payments.confirm', 'receipts.send', 'reminders.send',
            'buyers.verify', 'enquiries.manage', 'documents.manage', 'activity.view',
        ],
        'cashier' => ['payments.record', 'receipts.send'],
        'owner' => [], // owners reach only their own parcels, checked by ownership
    ];

    public static function ensurePermissions(): void
    {
        foreach (self::PERMISSIONS as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    // Safe to run again: adds anything missing and resets the four roles to the lists above
    public static function createFor(Organization $org): void
    {
        self::ensurePermissions();

        foreach (self::ROLES as $name => $perms) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'team_id' => $org->id]);
            $role->syncPermissions($perms);
        }
    }

    // Give someone a role in their own company, replacing any role they had there
    public static function assign(User $user, string $role): void
    {
        if (! Role::where('team_id', $user->org_id)->where('name', $role)->exists()) {
            self::createFor($user->organization ?? Organization::findOrFail($user->org_id));
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($user->org_id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->syncRoles([$role]);
        $registrar->setPermissionsTeamId($previous); // don't disturb the current request
    }
}
