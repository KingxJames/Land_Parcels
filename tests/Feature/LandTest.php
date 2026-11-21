<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Tests\TestCase;

class LandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->org = Organization::create(['name' => 'Faber Realty']);
    }

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return $this->makeUser(['full_name' => "U$n", 'email' => "land$n@example.test", 'role' => $role, 'status' => 'active']);
    }

    private function land(array $over = []): Land
    {
        return Land::create(array_merge(['org_id' => $this->org->id, 'name' => 'Cayo Ridge', 'place' => 'San Ignacio, Cayo'], $over));
    }

    public function test_public_list_shows_only_public_lands_in_order(): void
    {
        $this->land(['name' => 'Hidden', 'is_public' => false]);
        $this->land(['name' => 'Second', 'is_public' => true, 'sort_order' => 2]);
        $this->land(['name' => 'First', 'is_public' => true, 'sort_order' => 1]);

        $this->getJson('/api/v1/public/lands')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'First')
            ->assertJsonPath('1.name', 'Second')
            ->assertJsonMissingPath('0.site_plan_path');
    }

    public function test_private_land_is_hidden_from_public(): void
    {
        $land = $this->land();
        $this->getJson("/api/v1/public/lands/{$land->id}")->assertNotFound();
    }

    public function test_owners_and_guests_cannot_manage_lands(): void
    {
        $this->getJson('/api/v1/lands')->assertUnauthorized();

        Sanctum::actingAs($this->user('owner'));
        $this->getJson('/api/v1/lands')->assertForbidden();
        $this->postJson('/api/v1/lands', ['org_id' => $this->org->id, 'name' => 'X', 'place' => 'Y'])->assertForbidden();
    }

    public function test_staff_can_create_with_site_plan_and_update(): void
    {
        Sanctum::actingAs($this->user('office_admin'));

        $res = $this->post('/api/v1/lands', [
            'org_id' => $this->org->id, 'name' => 'Island Estates', 'place' => 'Caye Caulker',
            'site_plan' => UploadedFile::fake()->image('plan.jpg', 800, 600),
            'plan_width_ft' => 1200, 'plan_height_ft' => 900, 'is_public' => '1',
        ], ['Accept' => 'application/json']);

        $res->assertCreated()->assertJsonPath('has_site_plan', true)->assertJsonPath('is_public', true);
        $land = Land::findOrFail($res->json('id'));
        $old = $land->site_plan_path;
        Storage::disk('local')->assertExists($old);

        // Replace the plan: old file goes away
        $this->post("/api/v1/lands/{$land->id}", [
            '_method' => 'PATCH', 'name' => 'Island Estates II',
            'site_plan' => UploadedFile::fake()->image('plan2.png'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('name', 'Island Estates II');

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($land->fresh()->site_plan_path);
    }

    public function test_validation(): void
    {
        Sanctum::actingAs($this->user('office_admin'));

        $this->postJson('/api/v1/lands', ['org_id' => 'not-a-uuid', 'plan_width_ft' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'place', 'plan_width_ft'])
            ->assertJsonMissingValidationErrors('org_id'); // staff's own company is always used

        $other = Organization::create(['name' => 'Other']);
        $id = $this->postJson('/api/v1/lands', ['org_id' => $other->id, 'name' => 'Mine', 'place' => 'Here'])->assertCreated()->json('id');
        $this->assertSame($this->org->id, Land::find($id)->org_id);
    }

    public function test_site_plan_access(): void
    {
        $public = $this->land(['is_public' => true, 'site_plan_path' => 'site-plans/a.jpg']);
        $private = $this->land(['site_plan_path' => 'site-plans/b.jpg']);
        $public->forceFill(['site_plan_path' => 'site-plans/a.jpg'])->save();
        $private->forceFill(['site_plan_path' => 'site-plans/b.jpg'])->save();
        Storage::disk('local')->put('site-plans/a.jpg', 'img');
        Storage::disk('local')->put('site-plans/b.jpg', 'img');

        $this->get("/api/v1/lands/{$public->id}/site-plan")->assertOk();
        $this->get("/api/v1/lands/{$private->id}/site-plan")->assertNotFound();

        Sanctum::actingAs($this->user('office_admin'));
        $this->get("/api/v1/lands/{$private->id}/site-plan")->assertOk();
    }

    public function test_staff_can_delete_land_and_its_plan(): void
    {
        $land = $this->land();
        $land->forceFill(['site_plan_path' => 'site-plans/c.jpg'])->save();
        Storage::disk('local')->put('site-plans/c.jpg', 'img');
        Sanctum::actingAs($this->user('super_admin'));

        $this->deleteJson("/api/v1/lands/{$land->id}")->assertNoContent();
        $this->assertNull(Land::find($land->id));
        Storage::disk('local')->assertMissing('site-plans/c.jpg');
    }

    public function test_organization_with_lands_cannot_be_deleted(): void
    {
        $this->land();
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->org->delete();
    }
}
