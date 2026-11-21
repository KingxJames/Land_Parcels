<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Land;
use Modules\LandTracker\Models\Parcel;
use Tests\TestCase;

// Company A's staff must never see or touch company B's records
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $staffA;

    private Parcel $parcelB;

    private Land $landB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Organization::create(['name' => 'Alpha Lands']);
        $this->b = Organization::create(['name' => 'Beta Homes']);
        $this->staffA = $this->makeUser(['full_name' => 'A', 'email' => 'a@example.test', 'role' => 'office_admin', 'status' => 'active', 'org_id' => $this->a->id]);
        $this->landB = Land::create(['org_id' => $this->b->id, 'name' => 'Beta Ridge', 'place' => 'Cayo', 'is_public' => true]);
        $this->parcelB = $this->landB->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 1, 'price' => 100]);
    }

    public function test_other_companys_records_are_not_found(): void
    {
        Sanctum::actingAs($this->staffA);

        $this->getJson("/api/v1/lands/{$this->landB->id}")->assertNotFound();
        $this->getJson("/api/v1/lands/{$this->landB->id}/parcels")->assertNotFound();
        $this->patchJson("/api/v1/parcels/{$this->parcelB->id}", ['price' => 1])->assertNotFound();
        $this->getJson("/api/v1/parcels/{$this->parcelB->id}/payments")->assertNotFound();
        $this->deleteJson("/api/v1/lands/{$this->landB->id}")->assertNotFound();
        $this->assertSame('100.00', $this->parcelB->fresh()->price);
    }

    public function test_lists_only_show_own_company(): void
    {
        Land::create(['org_id' => $this->a->id, 'name' => 'Alpha Hills', 'place' => 'Belize']);
        Sanctum::actingAs($this->staffA);

        $this->getJson('/api/v1/lands')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Alpha Hills');
        $this->getJson('/api/v1/activity')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/enquiries')->assertJsonCount(0, 'data');
    }

    public function test_cannot_reserve_for_another_companys_customer(): void
    {
        $landA = Land::create(['org_id' => $this->a->id, 'name' => 'Alpha Hills', 'place' => 'Belize']);
        $parcelA = $landA->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 1, 'price' => 100]);
        $buyerB = $this->makeUser(['full_name' => 'Bob', 'email' => 'bob@example.test', 'role' => 'owner', 'status' => 'active', 'org_id' => $this->b->id]);
        Sanctum::actingAs($this->staffA);

        $this->postJson("/api/v1/parcels/{$parcelA->id}/reserve", ['owner_id' => $buyerB->id, 'hold_until' => now()->addWeek()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    }

    public function test_platform_owner_sees_every_company(): void
    {
        Sanctum::actingAs($this->makeUser(['full_name' => 'P', 'email' => 'p@example.test', 'role' => 'super_admin', 'status' => 'active', 'is_platform_admin' => true]));

        $this->getJson("/api/v1/lands/{$this->landB->id}")->assertOk();
        $this->getJson('/api/v1/lands')->assertJsonCount(1, 'data');
    }

    public function test_suspended_company_is_locked_out_and_hidden(): void
    {
        DB::table('organizations')->where('id', $this->b->id)->update(['status' => 'suspended']);
        Sanctum::actingAs($this->makeUser(['full_name' => 'B', 'email' => 'b@example.test', 'role' => 'office_admin', 'status' => 'active', 'org_id' => $this->b->id]));

        $this->getJson('/api/v1/lands')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/public/lands')->assertJsonCount(0);
        $this->getJson("/api/v1/public/lands/{$this->landB->id}")->assertNotFound();
        $this->getJson("/api/v1/public/orgs/{$this->b->slug}")->assertNotFound();
    }

    public function test_same_email_can_exist_in_two_companies_but_not_twice_in_one(): void
    {
        $this->makeUser(['full_name' => 'X', 'email' => 'same@example.test', 'role' => 'owner', 'org_id' => $this->a->id]);
        $this->makeUser(['full_name' => 'X', 'email' => 'Same@Example.test', 'role' => 'owner', 'org_id' => $this->b->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeUser(['full_name' => 'X', 'email' => 'SAME@example.test', 'role' => 'owner', 'org_id' => $this->a->id]);
    }

    public function test_only_platform_owner_may_have_no_company(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeUser(['full_name' => 'Nobody', 'email' => 'n@example.test', 'role' => 'owner', 'org_id' => null]);
    }
}
