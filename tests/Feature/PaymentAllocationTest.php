<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Instalment;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\PaymentAllocation;
use Modules\LandTracker\Services\PaymentAllocator;
use Tests\TestCase;

class PaymentAllocationTest extends TestCase
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
        $this->parcel = $land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 3000]);
        $this->staff = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']);
        $this->buyer = $this->makeUser(['full_name' => 'B', 'email' => 'b@example.test', 'role' => 'owner', 'status' => 'active']);
        DB::table('parcels')->where('id', $this->parcel->id)
            ->update(['status' => 'reserved', 'owner_id' => $this->buyer->id, 'hold_until' => now()->addMonth()->toDateString()]);
        Sanctum::actingAs($this->staff);
    }

    // Deposit 1000, then two months of 1000
    private function plan(): void
    {
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan",
            ['deposit' => 1000, 'monthly_amount' => 1000, 'start_date' => now()->toDateString()])->assertCreated();
    }

    private function cash(string $amount): string
    {
        return $this->postJson("/api/v1/parcels/{$this->parcel->id}/payments",
            ['amount' => $amount, 'method' => 'cash', 'paid_on' => now()->toDateString()])->assertCreated()->json('id');
    }

    private function paid(): array
    {
        return Instalment::orderBy('seq')->pluck('amount_paid')->all();
    }

    public function test_one_payment_covers_several_months_oldest_first(): void
    {
        $this->plan();
        $id = $this->cash('1500.00');

        $this->assertSame(['1000.00', '500.00', '0.00'], $this->paid());
        $this->getJson("/api/v1/payments/{$id}")->assertJsonCount(2, 'allocations')
            ->assertJsonPath('allocations.0.instalment.label', 'Deposit');

        $this->cash('1200.00');
        $this->assertSame(['1000.00', '1000.00', '700.00'], $this->paid());
    }

    public function test_money_taken_before_the_plan_is_applied_when_it_is_signed(): void
    {
        $this->cash('1000.00');
        $this->assertSame(0, PaymentAllocation::count());

        $this->plan();
        $this->assertSame(['1000.00', '0.00', '0.00'], $this->paid());
    }

    public function test_overpayment_stays_as_credit(): void
    {
        $this->plan();
        $this->cash('3250.00');

        $this->getJson("/api/v1/parcels/{$this->parcel->id}/plan")
            ->assertJsonPath('summary.paid_off', true)
            ->assertJsonPath('summary.credit', '250.00');
    }

    public function test_pending_and_rejected_money_is_not_applied_until_confirmed(): void
    {
        $this->plan();
        Sanctum::actingAs($this->buyer);
        $id = $this->postJson("/api/v1/my/parcels/{$this->parcel->id}/payments",
            ['amount' => '1000', 'method' => 'ekyash', 'paid_on' => now()->toDateString(), 'reference' => 'E1'])->json('id');
        $this->assertSame(['0.00', '0.00', '0.00'], $this->paid());

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payments/{$id}/confirm")->assertOk();
        $this->assertSame(['1000.00', '0.00', '0.00'], $this->paid());
    }

    public function test_running_again_changes_nothing_and_totals_match(): void
    {
        $this->plan();
        $this->cash('1234.56');
        app(PaymentAllocator::class)->allocateForParcel($this->parcel->id);
        app(PaymentAllocator::class)->allocateForParcel($this->parcel->id);

        $this->assertSame('1234.56', number_format((float) PaymentAllocation::sum('amount'), 2, '.', ''));
        $this->assertSame('1234.56', number_format((float) Instalment::sum('amount_paid'), 2, '.', ''));
    }
}
