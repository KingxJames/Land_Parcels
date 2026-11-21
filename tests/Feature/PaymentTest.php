<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Payment;
use Modules\LandTracker\Services\PaymentLedger;
use Tests\TestCase;

class PaymentTest extends TestCase
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
        DB::table('parcels')->where('id', $this->parcel->id)
            ->update(['status' => 'reserved', 'owner_id' => $this->buyer->id, 'hold_until' => now()->addMonth()->toDateString()]);
    }

    private function pay(array $over = []): array
    {
        return array_merge(['amount' => '500.00', 'method' => 'ekyash', 'paid_on' => now()->toDateString(), 'reference' => 'EK-123'], $over);
    }

    public function test_office_payment_is_confirmed_by_the_recorder(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/parcels/{$this->parcel->id}/payments", $this->pay(['method' => 'cash', 'reference' => 'Receipt 7']))
            ->assertCreated()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('source', 'office')
            ->assertJsonPath('owner_id', $this->buyer->id)
            ->assertJsonPath('recorder.id', $this->staff->id)
            ->assertJsonPath('confirmer.id', $this->staff->id);
    }

    public function test_staff_cannot_record_card_or_future_payments(): void
    {
        Sanctum::actingAs($this->staff);
        $url = "/api/v1/parcels/{$this->parcel->id}/payments";

        $this->postJson($url, $this->pay(['method' => 'card']))->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->postJson($url, $this->pay(['paid_on' => now()->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('paid_on');
        $this->postJson($url, $this->pay(['amount' => 0]))->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_buyer_reports_transfer_and_staff_confirm(): void
    {
        Sanctum::actingAs($this->buyer);
        $id = $this->postJson("/api/v1/my/parcels/{$this->parcel->id}/payments", $this->pay())
            ->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('source', 'online')
            ->assertJsonPath('recorded_by', null)->json('id');

        $this->postJson("/api/v1/payments/{$id}/confirm")->assertForbidden(); // buyers can't confirm

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payments?status=pending')->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/payments/{$id}/confirm")->assertOk()
            ->assertJsonPath('status', 'confirmed')->assertJsonPath('confirmer.id', $this->staff->id);
        $this->postJson("/api/v1/payments/{$id}/reject")->assertUnprocessable(); // already decided
    }

    public function test_rejected_reference_can_be_reported_again_but_not_duplicated(): void
    {
        Sanctum::actingAs($this->buyer);
        $url = "/api/v1/my/parcels/{$this->parcel->id}/payments";
        $id = $this->postJson($url, $this->pay())->assertCreated()->json('id');
        $this->postJson($url, $this->pay(['reference' => 'ek-123 ']))->assertUnprocessable()->assertJsonValidationErrors('reference');

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/payments/{$id}/reject")->assertOk()->assertJsonPath('status', 'rejected');

        Sanctum::actingAs($this->buyer);
        $this->postJson($url, $this->pay())->assertCreated();
    }

    public function test_buyer_rules(): void
    {
        Sanctum::actingAs($this->buyer);
        $url = "/api/v1/my/parcels/{$this->parcel->id}/payments";

        $this->postJson($url, $this->pay(['method' => 'cash']))->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->postJson($url, $this->pay(['reference' => null]))->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->postJson($url, $this->pay(['provider_txn_id' => 'fake']))->assertUnprocessable()->assertJsonValidationErrors('provider_txn_id');

        Sanctum::actingAs($this->makeUser(['full_name' => 'X', 'email' => 'x@example.test', 'role' => 'owner', 'status' => 'active']));
        $this->postJson($url, $this->pay())->assertNotFound();
        $this->getJson($url)->assertNotFound();
    }

    public function test_no_payments_for_an_available_parcel(): void
    {
        DB::table('parcels')->where('id', $this->parcel->id)->update(['status' => 'available', 'owner_id' => null, 'hold_until' => null]);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/parcels/{$this->parcel->id}/payments", $this->pay(['method' => 'cash']))
            ->assertUnprocessable()->assertJsonValidationErrors('parcel');
    }

    public function test_staff_cannot_confirm_their_own_payment(): void
    {
        DB::table('parcels')->where('id', $this->parcel->id)->update(['owner_id' => $this->staff->id]);
        Sanctum::actingAs($this->staff);
        $id = $this->postJson("/api/v1/my/parcels/{$this->parcel->id}/payments", $this->pay())->assertCreated()->json('id');

        $this->postJson("/api/v1/payments/{$id}/confirm")->assertUnprocessable();
    }

    public function test_card_payment_from_gateway_is_confirmed_and_never_duplicated(): void
    {
        $ledger = app(PaymentLedger::class);
        $p = $ledger->recordCardPayment($this->parcel, $this->buyer, '750.00', 'ch_1', now()->toDateString());

        $this->assertSame('confirmed', $p->status->value);
        $this->assertNull($p->confirmed_by);

        $this->expectException(QueryException::class);
        $ledger->recordCardPayment($this->parcel, $this->buyer, '750.00', 'ch_1', now()->toDateString());
    }

    public function test_payments_block_release(): void
    {
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/payments", $this->pay(['method' => 'cash']))->assertCreated();

        $this->postJson("/api/v1/parcels/{$this->parcel->id}/release")->assertUnprocessable();
    }

    public function test_database_rejects_inconsistent_rows(): void
    {
        Sanctum::actingAs($this->buyer);
        $id = $this->postJson("/api/v1/my/parcels/{$this->parcel->id}/payments", $this->pay())->json('id');

        $this->expectException(QueryException::class);
        DB::table('payments')->where('id', $id)->update(['status' => 'confirmed']); // confirmed with no time
    }

    public function test_buyer_sees_only_own_payments(): void
    {
        Sanctum::actingAs($this->buyer);
        $this->postJson("/api/v1/my/parcels/{$this->parcel->id}/payments", $this->pay())->assertCreated();
        $this->getJson("/api/v1/my/parcels/{$this->parcel->id}/payments")->assertOk()->assertJsonCount(1);
        $this->assertSame(1, Payment::count());
    }
}
