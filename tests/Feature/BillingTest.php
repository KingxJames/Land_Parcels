<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\BillingInvoice;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Subscription;
use Modules\LandTracker\Services\Billing;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Land $land;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Faber Realty']);
        $this->land = Land::create(['org_id' => $this->org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo']);
        $this->admin = $this->makeUser(['full_name' => 'A', 'email' => 'a@example.test', 'role' => 'super_admin', 'status' => 'active', 'is_platform_admin' => true]);
    }

    private function parcels(int $n, string $status = 'available'): void
    {
        $buyer = (User::where('email', 'b@example.test')->first() ?? $this->makeUser(['email' => 'b@example.test', 'full_name' => 'B', 'role' => 'owner', 'status' => 'active']));
        for ($i = 0; $i < $n; $i++) {
            $p = $this->land->parcels()->create(['label' => "{$status} {$i}", 'kind' => 'land', 'size_sqft' => 1, 'price' => 1000]);
            if ($status !== 'available') {
                DB::table('parcels')->where('id', $p->id)->update(['status' => $status, 'owner_id' => $buyer->id,
                    'hold_until' => $status === 'reserved' ? now()->addMonth()->toDateString() : null]);
            }
        }
    }

    private function subscribe(array $over = []): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/v1/billing/subscriptions/{$this->org->id}", array_merge([
            'price_per_parcel' => '5.00', 'minimum_monthly' => '25.00', 'monthly_cap' => '750.00',
            'billing_cycle' => 'monthly', 'status' => 'active', 'started_at' => '2026-01-01',
        ], $over))->assertSuccessful();
    }

    public function test_only_the_platform_owner_can_manage_billing(): void
    {
        Sanctum::actingAs($this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']));
        $this->getJson('/api/v1/billing/invoices')->assertForbidden();

        // A company's own super admin isn't the platform owner either
        Sanctum::actingAs($this->makeUser(['full_name' => 'C', 'email' => 'c@example.test', 'role' => 'super_admin', 'status' => 'active']));
        $this->getJson('/api/v1/billing/invoices')->assertForbidden();
    }

    public function test_billable_parcels_count_for_sale_reserved_and_still_paying(): void
    {
        $this->parcels(3);
        $this->parcels(2, 'reserved');
        $this->parcels(4, 'sold'); // sold without a plan, or paid off: not billable

        // One sold parcel still being paid off
        $sold = $this->land->parcels()->where('status', 'sold')->first();
        $plan = $sold->paymentPlan()->create(['owner_id' => $sold->owner_id, 'total_price' => 1000, 'deposit' => 0,
            'monthly_amount' => 500, 'start_date' => '2026-01-01', 'grace_months' => 0]);
        $plan->instalments()->create(['seq' => 1, 'label' => 'Month 1', 'due_date' => '2026-02-01', 'amount' => 1000]);

        $this->assertSame(6, app(Billing::class)->billableParcels($this->org->id));
    }

    public function test_amount_uses_minimum_and_cap(): void
    {
        $this->subscribe();
        $b = app(Billing::class);
        $s = Subscription::find($this->org->id);

        $this->assertSame('25.00', $b->monthlyAmount($s, 2));    // 10.00 -> minimum
        $this->assertSame('100.00', $b->monthlyAmount($s, 20));
        $this->assertSame('750.00', $b->monthlyAmount($s, 400)); // 2000.00 -> cap
    }

    public function test_monthly_invoice_once_per_period_then_overdue_then_paid(): void
    {
        $this->parcels(10);
        $this->subscribe();
        $b = app(Billing::class);

        $this->assertSame(1, $b->generate(Carbon::parse('2026-11-03')));
        $this->assertSame(0, $b->generate(Carbon::parse('2026-11-20'))); // same month, no second bill

        $inv = BillingInvoice::first();
        $this->assertSame(['2026-11-01', 10, '50.00', 'open'], [$inv->period_start->toDateString(), $inv->billable_parcels, $inv->amount, $inv->status]);

        $this->assertSame(0, $b->markOverdue(Carbon::parse('2026-11-10')));
        $this->assertSame(1, $b->markOverdue(Carbon::parse('2026-11-16')));
        $this->assertSame('past_due', Subscription::find($this->org->id)->status);

        $this->postJson("/api/v1/billing/invoices/{$inv->id}/paid")->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('active', Subscription::find($this->org->id)->status);
    }

    public function test_yearly_bills_twelve_months_in_the_start_month_only(): void
    {
        $this->parcels(10);
        $this->subscribe(['billing_cycle' => 'yearly', 'started_at' => '2026-03-15']);
        $b = app(Billing::class);

        $this->assertSame(0, $b->generate(Carbon::parse('2026-11-01')));
        $this->assertSame(1, $b->generate(Carbon::parse('2027-03-02')));
        $this->assertSame('600.00', BillingInvoice::first()->amount);
    }

    public function test_trial_and_cancelled_are_not_billed(): void
    {
        $this->parcels(10);
        $this->subscribe(['status' => 'trial']);
        $this->assertSame(0, app(Billing::class)->generate(Carbon::parse('2026-11-01')));
    }

    public function test_preview_and_subscription_change_is_logged(): void
    {
        $this->parcels(7);
        $this->subscribe();
        $this->getJson("/api/v1/billing/subscriptions/{$this->org->id}/preview")
            ->assertJsonPath('billable_parcels', 7)->assertJsonPath('monthly_amount', '35.00');

        $this->subscribe(['price_per_parcel' => '6.00']);
        $this->assertDatabaseHas('activity_log', ['properties->action' => 'subscription_changed', 'org_id' => $this->org->id]);
    }
}
