<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Support\DefaultRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

// Permissions and each company's four roles. Safe to run again.
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        DefaultRoles::ensurePermissions();
        Organization::each(fn (Organization $org) => DefaultRoles::createFor($org));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
