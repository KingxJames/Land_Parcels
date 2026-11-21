<?php

use App\Models\Organization;
use App\Support\DefaultRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Roles now live in model_has_roles (per company). Move each person's old role column
// value into their company role, then remove the column and its type.
return new class extends Migration
{
    public function up(): void
    {
        Organization::each(fn (Organization $org) => DefaultRoles::createFor($org));

        DB::statement(<<<'SQL'
            INSERT INTO model_has_roles (role_id, model_type, model_id, team_id)
            SELECT r.id, 'App\Models\User', u.id, u.org_id
            FROM users u
            JOIN roles r ON r.team_id = u.org_id AND r.name = u.role::text AND r.guard_name = 'web'
            WHERE u.org_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        DB::statement('ALTER TABLE users DROP COLUMN role');
        DB::statement('DROP TYPE IF EXISTS user_role');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // Puts the column back, filled from each person's company role (cashier and custom roles become owner)
    public function down(): void
    {
        DB::statement("CREATE TYPE user_role AS ENUM ('super_admin', 'office_admin', 'owner')");
        DB::statement("ALTER TABLE users ADD COLUMN role user_role NOT NULL DEFAULT 'owner'");
        DB::statement(<<<'SQL'
            UPDATE users u SET role = r.name::user_role
            FROM model_has_roles m JOIN roles r ON r.id = m.role_id
            WHERE m.model_id = u.id AND m.team_id = u.org_id AND r.name IN ('super_admin', 'office_admin')
        SQL);
        DB::statement("UPDATE users SET role = 'super_admin' WHERE is_platform_admin");
    }
};
