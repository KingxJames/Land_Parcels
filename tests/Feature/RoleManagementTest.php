<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Faber Realty']);
        $this->admin = $this->makeUser(['full_name' => 'Admin', 'email' => 'admin@example.test', 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($this->admin);
    }

    private function role(string $name): Role
    {
        return Role::where('team_id', $this->org->id)->where('name', $name)->firstOrFail();
    }

    public function test_list_roles_and_permissions(): void
    {
        $this->getJson('/api/v1/roles')->assertOk()
            ->assertJsonCount(4, 'roles')
            ->assertJsonCount(15, 'all_permissions')
            ->assertJsonPath('roles.0.name', 'super_admin')->assertJsonPath('roles.0.locked', true);
    }

    public function test_custom_role_can_be_added_used_and_removed(): void
    {
        $id = $this->postJson('/api/v1/roles', ['name' => 'Sales agent', 'permissions' => ['parcels.sell', 'enquiries.manage']])
            ->assertCreated()->assertJsonPath('built_in', false)->json('id');

        $person = $this->postJson('/api/v1/people', ['full_name' => 'Sam', 'email' => 'Sam@Example.test', 'role' => 'Sales agent'])
            ->assertCreated()->assertJsonPath('roles', ['Sales agent'])->assertJsonPath('status', 'invited')->json('id');

        // The new person can handle enquiries but not payments
        $sam = User::find($person);
        $sam->forceFill(['status' => 'active'])->save();
        Sanctum::actingAs($sam);
        $this->getJson('/api/v1/enquiries')->assertOk();
        $this->getJson('/api/v1/payments')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/roles/{$id}")->assertConflict(); // someone still has it
        $this->patchJson("/api/v1/people/{$person}", ['role' => 'cashier'])->assertOk()->assertJsonPath('roles', ['cashier']);
        $this->deleteJson("/api/v1/roles/{$id}")->assertNoContent();
    }

    public function test_built_in_roles_rules(): void
    {
        $this->patchJson("/api/v1/roles/{$this->role('super_admin')->id}", ['permissions' => []])->assertUnprocessable();
        $this->patchJson("/api/v1/roles/{$this->role('owner')->id}", ['permissions' => ['payments.record']])->assertUnprocessable();
        $this->patchJson("/api/v1/roles/{$this->role('cashier')->id}", ['name' => 'Teller'])->assertUnprocessable();
        $this->deleteJson("/api/v1/roles/{$this->role('cashier')->id}")->assertUnprocessable();
        $this->postJson('/api/v1/roles', ['name' => 'cashier', 'permissions' => []])->assertUnprocessable();

        // Changing what a cashier may do is allowed
        $this->patchJson("/api/v1/roles/{$this->role('cashier')->id}", ['permissions' => ['payments.record', 'receipts.send', 'reminders.send']])
            ->assertOk()->assertJsonCount(3, 'permissions');
        $this->assertDatabaseHas('activity_log', ['properties->action' => 'role_updated', 'org_id' => $this->org->id]);
    }

    public function test_no_one_locks_the_company_out(): void
    {
        $this->patchJson("/api/v1/people/{$this->admin->id}", ['role' => 'cashier'])->assertUnprocessable();
        $this->patchJson("/api/v1/people/{$this->admin->id}", ['status' => 'switched_off'])->assertUnprocessable();

        // A second Super admin can't demote the last other one either
        $second = $this->makeUser(['full_name' => 'Two', 'email' => 'two@example.test', 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($second);
        $this->patchJson("/api/v1/people/{$this->admin->id}", ['role' => 'office_admin'])->assertOk(); // two existed
        Sanctum::actingAs($this->admin->fresh());
        $this->getJson('/api/v1/people')->assertForbidden(); // now an office admin
    }

    public function test_people_list_and_company_separation(): void
    {
        $this->makeUser(['full_name' => 'Bea', 'email' => 'bea@example.test', 'role' => 'owner']);
        $this->getJson('/api/v1/people?role=owner')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'Bea');
        $this->postJson('/api/v1/people', ['full_name' => 'X', 'email' => 'bea@example.test', 'role' => 'owner'])->assertUnprocessable();

        $other = Organization::create(['name' => 'Other']);
        $stranger = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'owner', 'org_id' => $other->id]);
        $otherRole = Role::where('team_id', $other->id)->where('name', 'cashier')->first();
        $this->patchJson("/api/v1/people/{$stranger->id}", ['full_name' => 'Hacked'])->assertNotFound();
        $this->patchJson("/api/v1/roles/{$otherRole->id}", ['permissions' => []])->assertNotFound();

        Sanctum::actingAs($this->makeUser(['full_name' => 'O', 'email' => 'o@example.test', 'role' => 'office_admin', 'status' => 'active']));
        $this->getJson('/api/v1/roles')->assertForbidden();
    }
}
