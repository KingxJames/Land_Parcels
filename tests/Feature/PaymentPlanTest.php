<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Tests\TestCase;

class PaymentPlanTest extends TestCase
{
    use RefreshDatabase;

    private Parcel $parcel;

    private User $staff;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Faber Realty']);
        $land = Land::create(['org_id' => $org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo']);
        $this->parcel = $land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 12000]);
        $this->staff = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']);
        $this->buyer = $this->makeUser(['full_name' => 'B', 'email' => 'b@example.test', 'role' => 'owner', 'status' => 'active']);
    }

    private function reserve(): void
    {
        DB::table('parcels')->where('id', $this->parcel->id)
            ->update(['status' => 'reserved', 'owner_id' => $this->buyer->id, 'hold_until' => now()->addMonth()->toDateString()]);
    }

    private function terms(array $over = []): array
    {
        return array_merge(['deposit' => 2000, 'monthly_amount' => 2500, 'start_date' => now()->addDay()->toDateString()], $over);
    }

    public function test_plan_needs_a_reserved_or_sold_parcel(): void
    {
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms())->assertUnprocessable();
    }

    public function test_staff_creates_plan_from_parcel_price_and_owner(): void
    {
        $this->reserve();
        Sanctum::actingAs($this->staff);

        $res = $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms())
            ->assertCreated()
            ->assertJsonPath('total_price', '12000.00')
            ->assertJsonPath('owner_id', $this->buyer->id)
            ->assertJsonCount(5, 'instalments') // deposit + 4 months of 2500
            ->assertJsonPath('instalments.0.label', 'Deposit')
            ->assertJsonPath('instalments.4.amount', '2500.00')
            ->assertJsonPath('summary.outstanding', '12000.00')
            ->assertJsonPath('summary.next_due.label', 'Deposit');

        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms())->assertConflict();
    }

    public function test_bad_terms_are_rejected(): void
    {
        $this->reserve();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms(['deposit' => 99999]))
            ->assertUnprocessable()->assertJsonValidationErrors('deposit');
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms(['monthly_amount' => 0.01]))
            ->assertUnprocessable()->assertJsonValidationErrors('monthly_amount');
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms(['deposit' => 1.234]))
            ->assertUnprocessable()->assertJsonValidationErrors('deposit');
    }

    public function test_terms_can_change_until_something_is_paid(): void
    {
        $this->reserve();
        Sanctum::actingAs($this->staff);
        $url = "/api/v1/parcels/{$this->parcel->id}/plan";
        $this->postJson($url, $this->terms())->assertCreated();

        $this->putJson($url, $this->terms(['monthly_amount' => 0]))
            ->assertOk()->assertJsonCount(2, 'instalments')->assertJsonPath('instalments.1.label', 'Balance');

        DB::table('instalments')->where('seq', 0)->update(['amount_paid' => 500]);

        $this->putJson($url, $this->terms())->assertConflict();
        $this->deleteJson($url)->assertConflict();
        $this->getJson($url)->assertJsonPath('summary.paid', '500.00')->assertJsonPath('summary.outstanding', '11500.00')
            ->assertJsonPath('instalments.0.status', 'part_paid');
    }

    public function test_plan_blocks_release_until_cancelled(): void
    {
        $this->reserve();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms())->assertCreated();

        $this->postJson("/api/v1/parcels/{$this->parcel->id}/release")->assertUnprocessable();

        // Daily job leaves it alone even when the hold has run out
        DB::table('parcels')->where('id', $this->parcel->id)->update(['hold_until' => now()->subDay()->toDateString()]);
        $this->artisan('parcels:release-expired')->expectsOutput('Released 0 parcel(s).');

        $this->deleteJson("/api/v1/parcels/{$this->parcel->id}/plan")->assertNoContent();
        $this->assertDatabaseCount('instalments', 0);
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/release")->assertOk();
    }

    public function test_buyer_sees_own_plan_only(): void
    {
        $this->reserve();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms())->assertCreated();

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/v1/my/parcels/{$this->parcel->id}/plan")->assertOk()->assertJsonCount(5, 'instalments');
        $this->getJson("/api/v1/parcels/{$this->parcel->id}/plan")->assertForbidden();

        Sanctum::actingAs($this->makeUser(['full_name' => 'X', 'email' => 'x@example.test', 'role' => 'owner', 'status' => 'active']));
        $this->getJson("/api/v1/my/parcels/{$this->parcel->id}/plan")->assertNotFound();
    }

    public function test_preview_does_not_save(): void
    {
        Sanctum::actingAs($this->staff);
        $this->postJson('/api/v1/payment-plans/preview', $this->terms(['total_price' => 10000]))
            ->assertOk()->assertJsonCount(5, 'instalments');
        $this->assertDatabaseCount('payment_plans', 0);
    }

    public function test_database_keeps_paid_within_amount(): void
    {
        $this->reserve();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan", $this->terms())->assertCreated();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('instalments')->where('seq', 0)->update(['amount_paid' => 2000.01]);
    }
}
