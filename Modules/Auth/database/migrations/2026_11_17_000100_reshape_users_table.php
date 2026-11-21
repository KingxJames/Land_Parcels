<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Users: uuid ids, a company (org_id), a platform-owner flag, full_name, native enum types,
// email unique within a company, and last sign-in time.
//
// Works on a database that already has users: existing sign-ins are tied to the old
// whole-number ids, so tokens and sessions are cleared and everyone signs in again.
// One-way: there is no going back to whole-number ids.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')->truncate();
        DB::table('sessions')->truncate();

        DB::statement('DROP TYPE IF EXISTS user_status');
        DB::statement('DROP TYPE IF EXISTS user_role');
        DB::statement("CREATE TYPE user_status AS ENUM ('invited', 'active', 'switched_off')");
        // Kept until everything uses permissions (model_has_roles), then removed
        DB::statement("CREATE TYPE user_role AS ENUM ('super_admin', 'office_admin', 'owner')");

        DB::unprepared(<<<'SQL'
            -- uuid ids
            ALTER TABLE users ADD COLUMN new_id uuid NOT NULL DEFAULT gen_random_uuid();
            ALTER TABLE users DROP CONSTRAINT users_pkey;
            ALTER TABLE users DROP COLUMN id;
            ALTER TABLE users RENAME COLUMN new_id TO id;
            ALTER TABLE users ADD PRIMARY KEY (id);

            -- Laravel's enum() made text columns with checks; switch to the real types
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check;
            ALTER TABLE users ALTER COLUMN role DROP DEFAULT;
            ALTER TABLE users ALTER COLUMN role TYPE user_role USING role::user_role;
            ALTER TABLE users ALTER COLUMN role SET DEFAULT 'owner';
            ALTER TABLE users ALTER COLUMN status DROP DEFAULT;
            ALTER TABLE users ALTER COLUMN status TYPE user_status USING status::user_status;
            ALTER TABLE users ALTER COLUMN status SET DEFAULT 'invited';

            ALTER TABLE users RENAME COLUMN name TO full_name;

            -- Email: optional, unique within a company rather than across everyone
            ALTER TABLE users ALTER COLUMN email DROP NOT NULL;
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique;
            UPDATE users SET email = lower(email) WHERE email IS NOT NULL;

            -- The same person can have an account in two companies, so google_id isn't unique either
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_google_id_unique;
            CREATE INDEX users_google_id_index ON users (google_id);

            ALTER TABLE users ADD COLUMN org_id uuid REFERENCES organizations(id) ON DELETE RESTRICT;
            ALTER TABLE users ADD COLUMN is_platform_admin boolean NOT NULL DEFAULT false;
            ALTER TABLE users ADD COLUMN last_sign_in_at timestamptz;
            CREATE INDEX users_org_id_index ON users (org_id);
        SQL);

        // Existing super admins are the platform owner
        DB::statement("UPDATE users SET is_platform_admin = true WHERE role = 'super_admin'");

        // Anyone else already here needs a company; park them in one to sort out by hand
        if (DB::table('users')->where('is_platform_admin', false)->exists()) {
            $orgId = (string) \Illuminate\Support\Str::uuid();
            DB::table('organizations')->insert([
                'id' => $orgId, 'name' => 'Unassigned', 'slug' => 'unassigned', 'receipt_prefix' => 'U-',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('users')->where('is_platform_admin', false)->update(['org_id' => $orgId]);
        }

        DB::unprepared(<<<'SQL'
            -- Only the platform owner may have no company
            ALTER TABLE users ADD CONSTRAINT users_org_required CHECK (is_platform_admin OR org_id IS NOT NULL);

            CREATE UNIQUE INDEX users_org_email_unique ON users (org_id, lower(email))
                WHERE email IS NOT NULL AND org_id IS NOT NULL;
            CREATE UNIQUE INDEX users_platform_email_unique ON users (lower(email))
                WHERE email IS NOT NULL AND org_id IS NULL;

            -- Things that point at users follow the new id type (both tables were just emptied)
            ALTER TABLE sessions ALTER COLUMN user_id TYPE uuid USING NULL;
            DROP INDEX IF EXISTS personal_access_tokens_tokenable_type_tokenable_id_index;
            ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE uuid USING NULL;
            CREATE INDEX personal_access_tokens_tokenable_type_tokenable_id_index
                ON personal_access_tokens (tokenable_type, tokenable_id);
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Converting users to uuid ids is one-way.');
    }
};
