<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_new_company_gets_its_four_roles(): void
    {
        $org = Organization::create(['name' => 'Faber Realty']);

        $roles = Role::where('team_id', $org->id)->pluck('name')->sort()->values()->all();
        $this->assertSame(['cashier', 'office_admin', 'owner', 'super_admin'], $roles);

        $cashier = Role::where('team_id', $org->id)->where('name', 'cashier')->first();
        $this->assertEqualsCanonicalizing(['payments.record', 'receipts.send'], $cashier->permissions->pluck('name')->all());
    }

    public function test_a_role_works_only_in_its_own_company(): void
    {
        $a = Organization::create(['name' => 'Alpha Lands']);
        $b = Organization::create(['name' => 'Beta Homes']);
        $u = $this->makeUser(['full_name' => 'Ann', 'email' => 'ann@example.test', 'role' => 'office_admin', 'org_id' => $a->id]);

        setPermissionsTeamId($a->id);
        $u->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertTrue($u->hasRole('office_admin'));
        $this->assertTrue($u->can('payments.confirm'));
        $this->assertFalse($u->can('org.manage'));
        $this->assertTrue($u->isStaff());

        // The same role name in another company is a different role
        setPermissionsTeamId($b->id);
        $u->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertFalse($u->hasRole('office_admin'));
    }

    public function test_assigning_replaces_the_role(): void
    {
        Organization::create(['name' => 'Faber Realty']);
        $u = $this->makeUser(['full_name' => 'Ann', 'email' => 'ann@example.test', 'role' => 'owner']);
        $this->assertFalse($u->isStaff());

        \App\Support\DefaultRoles::assign($u, 'cashier');
        $this->assertSame(['cashier'], $u->companyRoleNames());
        $this->assertTrue($u->isStaff());
    }

    public function test_cashier_records_payments_but_cannot_change_parcels_or_confirm(): void
    {
        $org = Organization::create(['name' => 'Faber Realty']);
        $land = \Modules\LandTracker\Models\Land::create(['org_id' => $org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo']);
        $parcel = $land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 1, 'price' => 1000]);
        $buyer = $this->makeUser(['full_name' => 'B', 'email' => 'b@example.test', 'role' => 'owner', 'status' => 'active']);
        \Illuminate\Support\Facades\DB::table('parcels')->where('id', $parcel->id)
            ->update(['status' => 'reserved', 'owner_id' => $buyer->id, 'hold_until' => now()->addWeek()->toDateString()]);
        \Laravel\Sanctum\Sanctum::actingAs($this->makeUser(['full_name' => 'C', 'email' => 'c@example.test', 'role' => 'cashier', 'status' => 'active']));

        $this->getJson("/api/v1/lands/{$land->id}/parcels")->assertOk();                 // can look things up
        $this->postJson("/api/v1/parcels/{$parcel->id}/payments", ['amount' => 100, 'method' => 'cash', 'paid_on' => now()->toDateString()])
            ->assertCreated();
        $this->patchJson("/api/v1/parcels/{$parcel->id}", ['price' => 1])->assertForbidden();
        $this->postJson("/api/v1/parcels/{$parcel->id}/release")->assertForbidden();
        $this->getJson('/api/v1/buyer-profiles')->assertForbidden();                      // no ID details
        $this->getJson('/api/v1/enquiries')->assertForbidden();
    }

    public function test_owner_is_not_staff(): void
    {
        Organization::create(['name' => 'Faber Realty']);
        \Laravel\Sanctum\Sanctum::actingAs($this->makeUser(['full_name' => 'O', 'email' => 'o@example.test', 'role' => 'owner', 'status' => 'active']));

        $this->getJson('/api/v1/lands')->assertForbidden();
        $this->getJson('/api/v1/org')->assertForbidden();
    }

    public function test_platform_owner_passes_every_check(): void
    {
        $p = $this->makeUser(['full_name' => 'P', 'email' => 'p@example.test', 'role' => 'super_admin', 'is_platform_admin' => true]);

        $this->assertTrue($p->can('connections.manage'));
        $this->assertTrue($p->can('anything.at.all'));
        $this->assertSame(0, $p->roles()->count()); // no company, no company roles
    }

    public function test_seeder_is_safe_to_run_again(): void
    {
        Organization::create(['name' => 'Faber Realty']);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);

        $this->assertSame(15, \Spatie\Permission\Models\Permission::count());
        $this->assertSame(4, Role::count());
    }
}
