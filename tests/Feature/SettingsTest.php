<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Disks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Database\Seeders\SuperAdminSeeder;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_owner_comes_from_settings(): void
    {
        config(['app.platform_admin' => ['email' => 'Owner@Example.test', 'name' => 'The Owner']]);
        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class); // running again doesn't duplicate

        $u = User::sole();
        $this->assertSame(['owner@example.test', 'The Owner', true, null], [$u->email, $u->full_name, $u->is_platform_admin, $u->org_id]);
    }

    public function test_no_email_set_means_no_platform_owner(): void
    {
        config(['app.platform_admin' => ['email' => null, 'name' => 'x']]);
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_storage_places_come_from_settings(): void
    {
        config(['filesystems.uploads' => 's3', 'filesystems.id_scans' => 's3-private']);
        $this->assertSame(['s3', 's3-private'], [Disks::uploads(), Disks::idScans()]);
    }
}
