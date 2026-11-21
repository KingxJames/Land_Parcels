<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Instalment;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Receipt;
use Modules\LandTracker\Models\Reminder;
use Tests\TestCase;

class ReceiptReminderTest extends TestCase
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
        $this->buyer = $this->makeUser(['full_name' => 'Buyer', 'email' => 'b@example.test', 'role' => 'owner', 'status' => 'active']);
        DB::table('parcels')->where('id', $this->parcel->id)
            ->update(['status' => 'reserved', 'owner_id' => $this->buyer->id, 'hold_until' => now()->addMonth()->toDateString()]);
        Sanctum::actingAs($this->staff);
    }

    private function cash(string $amount, string $ref = null): array
    {
        return $this->postJson("/api/v1/parcels/{$this->parcel->id}/payments",
            ['amount' => $amount, 'method' => 'cash', 'paid_on' => now()->toDateString(), 'reference' => $ref])->assertCreated()->json();
    }

    private function plan(string $start): void
    {
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/plan",
            ['deposit' => 1000, 'monthly_amount' => 1000, 'start_date' => $start])->assertCreated();
    }

    public function test_every_confirmed_payment_gets_the_next_receipt_number_and_balance(): void
    {
        $this->plan(now()->toDateString());
        $a = $this->cash('1000.00');
        $b = $this->cash('500.00');

        $this->assertSame('FR-1001', $a['receipt']['receipt_no']); // Faber Realty -> FR-
        $this->assertSame('2000.00', $a['receipt']['balance_after']);
        $this->assertSame('FR-1002', $b['receipt']['receipt_no']);
        $this->assertSame('1500.00', $b['receipt']['balance_after']);
    }

    public function test_pending_gets_no_receipt_until_confirmed_and_rejected_never(): void
    {
        Sanctum::actingAs($this->buyer);
        $url = "/api/v1/my/parcels/{$this->parcel->id}/payments";
        $p1 = $this->postJson($url, ['amount' => '100', 'method' => 'ekyash', 'paid_on' => now()->toDateString(), 'reference' => 'E1'])->json('id');
        $p2 = $this->postJson($url, ['amount' => '100', 'method' => 'ekyash', 'paid_on' => now()->toDateString(), 'reference' => 'E2'])->json('id');
        $this->assertSame(0, Receipt::count());

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payments/{$p2}/reject")->assertOk();
        $this->postJson("/api/v1/payments/{$p1}/confirm")->assertOk()->assertJsonPath('receipt.receipt_no', 'FR-1001');
        $this->assertSame(1, Receipt::count());
    }

    public function test_buyer_can_print_own_receipt_only(): void
    {
        $r = $this->cash('250.00', 'Receipt book 7')['receipt'];

        $this->get("/api/v1/receipts/{$r['id']}/print")->assertOk()->assertSee('FR-1001')->assertSee('BZ$250.00')->assertSee('Faber Realty');

        Sanctum::actingAs($this->buyer);
        $this->get("/api/v1/receipts/{$r['id']}/print")->assertOk();
        $this->getJson("/api/v1/my/parcels/{$this->parcel->id}/payments")->assertJsonPath('0.receipt.receipt_no', 'FR-1001');

        Sanctum::actingAs($this->makeUser(['full_name' => 'X', 'email' => 'x@example.test', 'role' => 'owner', 'status' => 'active']));
        $this->get("/api/v1/receipts/{$r['id']}/print")->assertNotFound();
    }

    public function test_send_receipt_by_email_records_it_and_whatsapp_refuses(): void
    {
        Mail::fake();
        $r = $this->cash('250.00')['receipt'];

        $this->postJson("/api/v1/receipts/{$r['id']}/send", ['via' => 'whatsapp'])
            ->assertUnprocessable()->assertJsonValidationErrors('via');
        $this->assertNull(Receipt::find($r['id'])->sent_via);

        $this->postJson("/api/v1/receipts/{$r['id']}/send", ['via' => 'email'])
            ->assertOk()->assertJsonPath('sent_via', 'email');
        $this->assertNotNull(Receipt::find($r['id'])->sent_at);
    }

    public function test_staff_send_a_reminder(): void
    {
        Mail::fake();
        $this->plan(now()->toDateString());
        $inst = Instalment::where('seq', 1)->first();

        $this->postJson("/api/v1/instalments/{$inst->id}/remind", ['channel' => 'email'])
            ->assertCreated()->assertJsonPath('sent_by', $this->staff->id)->assertJsonPath('owner_id', $this->buyer->id);
        $this->assertStringContainsString('Month 1 payment of BZ$1,000.00 for Lot 1', Reminder::first()->message);

        $this->postJson("/api/v1/instalments/{$inst->id}/remind", ['channel' => 'sms'])->assertUnprocessable();
        $this->assertSame(1, Reminder::count());

        $this->getJson("/api/v1/parcels/{$this->parcel->id}/reminders")->assertJsonCount(1)->assertJsonPath('0.instalment.label', 'Month 1');
    }

    public function test_paid_instalments_get_no_reminder(): void
    {
        $this->plan(now()->toDateString());
        $this->cash('1000.00');
        $deposit = Instalment::where('seq', 0)->first();

        $this->postJson("/api/v1/instalments/{$deposit->id}/remind", ['channel' => 'email'])->assertUnprocessable();
    }

    public function test_daily_job_reminds_due_soon_and_overdue_once_a_day(): void
    {
        Mail::fake();
        // Deposit is 7 days overdue; Month 1 is moved to 3 days from now
        $this->plan(now()->subDays(7)->toDateString());
        Instalment::where('seq', 1)->update(['due_date' => now()->addDays(3)->toDateString()]);
        Instalment::where('seq', 2)->update(['due_date' => now()->addDays(10)->toDateString()]); // not a reminder day

        $this->artisan('reminders:send-due')->expectsOutput('Sent 2 reminder(s), 0 failed.')->assertSuccessful();
        $this->assertNull(Reminder::first()->sent_by);

        $this->artisan('reminders:send-due')->expectsOutput('Sent 0 reminder(s), 0 failed.');
    }
}
