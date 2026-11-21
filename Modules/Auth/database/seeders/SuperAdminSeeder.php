<?php

namespace Modules\Auth\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// The platform owner: no company, passes every check.
// Who that is comes from PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_NAME in .env.
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('app.platform_admin.email');
        if (! $email) {
            $this->command?->warn('PLATFORM_ADMIN_EMAIL is not set in .env, so no platform owner was created.');

            return;
        }

        $user = User::whereNull('org_id')->where('email', strtolower($email))->first()
            ?? new User(['email' => $email, 'status' => 'invited']);

        $user->fill(['full_name' => config('app.platform_admin.name')]);
        $user->forceFill(['is_platform_admin' => true, 'org_id' => null])->save();
    }
}
