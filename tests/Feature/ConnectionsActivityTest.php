<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Payment;
use Modules\LandTracker\Models\PaymentConnection;
use Modules\LandTracker\Models\PaymentNotice;
use Tests\TestCase;

class ConnectionsActivityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_0123456789abcdef';

    private Organization $org;

    private Parcel $parcel;

    private User $staff;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Faber Realty']);
        $land = Land::create(['org_id' => $this->org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo']);
        $this->parcel = $land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 22000]);
        $this->staff = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']);
        $this->buyer = $this->makeUser(['full_name' => 'B', 'email' => 'b@example.test', 'role' => 'owner', 'status' => 'active']);
        DB::table('parcels')->where('id', $this->parcel->id)
            ->update(['status' => 'reserved', 'owner_id' => $this->buyer->id, 'hold_until' => now()->addMonth()->toDateString()]);
    }

    private function connect(string $mode = 'live'): string
    {
        // Provider keys: company Super admins only
        Sanctum::actingAs($this->makeUser(['full_name' => 'Boss', 'email' => 'boss' . uniqid() . '@example.test', 'role' => 'super_admin', 'status' => 'active']));

        return $this->postJson('/api/v1/payment-connections', [
            'org_id' => $this->org->id, 'provider' => 'ekyash', 'merchant_id' => 'SID-77',
            'api_key' => 'sk_live_supersecret1234', 'callback_secret' => self::SECRET, 'mode' => $mode,
        ])->assertCreated()->json('id');
    }

    private function notice(string $id, array $body, ?string $secret = self::SECRET)
    {
        $raw = json_encode($body);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, $secret ?? 'wrong')];

        return $this->call('POST', "/api/v1/webhooks/payments/{$id}", [], [], [], $headers, $raw);
    }

    private function approved(array $over = []): array
    {
        return array_merge(['invoice_id' => 'INV-1', 'status' => 'approved', 'amount' => '500.00', 'transaction_id' => 'EK-999',
            'paid_on' => now()->toDateString(), 'metadata' => ['parcel_id' => $this->parcel->id, 'owner_id' => $this->buyer->id]], $over);
    }

    public function test_office_admin_cannot_manage_connections(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payment-connections')->assertForbidden();
    }

    public function test_keys_are_encrypted_and_never_returned(): void
    {
        $id = $this->connect();

        $raw = DB::table('payment_connections')->where('id', $id)->first();
        $this->assertStringNotContainsString('supersecret', $raw->api_key_encrypted);
        $this->assertStringNotContainsString('0123456789', $raw->callback_secret_encrypted);

        $this->getJson('/api/v1/payment-connections')->assertJsonPath('0.api_key_hint', '••••1234')
            ->assertJsonMissingPath('0.api_key_encrypted')->assertJsonMissingPath('0.callback_secret_encrypted');

        Sanctum::actingAs($this->buyer);
        $this->getJson('/api/v1/payment-connections')->assertForbidden();
    }

    public function test_approved_notice_becomes_a_confirmed_payment_once(): void
    {
        $id = $this->connect();
        $this->app['auth']->forgetGuards();

        $this->notice($id, $this->approved())->assertOk();
        $this->notice($id, $this->approved())->assertOk(); // provider resends

        $this->assertSame(1, Payment::count());
        $p = Payment::first();
        $this->assertSame(['ekyash', 'online', 'confirmed', 'EK-999'], [$p->method, $p->source->value, $p->status->value, $p->provider_txn_id]);
        $this->assertNotNull($p->receipt);
        $this->assertSame(2, PaymentNotice::whereNotNull('payment_id')->count());
    }

    public function test_bad_signature_is_refused_and_not_stored(): void
    {
        $id = $this->connect();
        $this->notice($id, $this->approved(), 'not-the-secret')->assertUnauthorized();
        $this->assertSame(0, PaymentNotice::count());
    }

    public function test_declined_and_test_mode_notices_are_kept_but_make_no_payment(): void
    {
        $live = $this->connect();
        $this->notice($live, $this->approved(['status' => 'declined']))->assertOk();

        PaymentConnection::find($live)->forceFill(['mode' => 'test'])->save();
        $this->notice($live, $this->approved())->assertOk();

        $this->assertSame(2, PaymentNotice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_notice_for_wrong_buyer_is_kept_unmatched(): void
    {
        $id = $this->connect();
        $other = $this->makeUser(['full_name' => 'X', 'email' => 'x@example.test', 'role' => 'owner', 'status' => 'active']);

        $this->notice($id, $this->approved(['metadata' => ['parcel_id' => $this->parcel->id, 'owner_id' => $other->id]]))->assertOk();

        $this->assertSame(0, Payment::count());
        $this->assertNull(PaymentNotice::first()->payment_id);
    }

    public function test_connection_with_notices_cannot_be_removed(): void
    {
        $id = $this->connect();
        $this->notice($id, $this->approved(['status' => 'declined']));
        Sanctum::actingAs($this->makeUser(['full_name' => 'Boss2', 'email' => 'boss2@example.test', 'role' => 'super_admin', 'status' => 'active']));
        $this->deleteJson("/api/v1/payment-connections/{$id}")->assertConflict();
    }

    public function test_price_change_is_logged_and_log_cannot_be_edited(): void
    {
        Sanctum::actingAs($this->staff);
        $this->patchJson("/api/v1/parcels/{$this->parcel->id}", ['price' => 24000])->assertOk();

        $entry = Activity::where('properties->action', 'price_changed')->first();
        $this->assertSame('Changed price of Lot 1', $entry->description);
        $this->assertSame(['parcels', 'updated', 'parcel', $this->parcel->id], [$entry->log_name, $entry->event, $entry->subject_type, $entry->subject_id]);
        $this->assertSame(['App\\Models\\User', $this->staff->id], [$entry->causer_type, $entry->causer_id]);
        $this->assertSame($this->org->id, $entry->org_id);
        $this->assertEquals(['price' => '22000.00'], $entry->properties['old']);
        $this->assertEquals(['price' => '24000.00'], $entry->properties['attributes']);

        $this->getJson("/api/v1/activity?subject_type=parcel&subject_id={$this->parcel->id}")
            ->assertJsonPath('data.0.description', 'Changed price of Lot 1')->assertJsonPath('data.0.causer.full_name', 'S');
        $this->getJson('/api/v1/activity?action=price_changed')->assertJsonCount(1, 'data');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activity_log')->where('id', $entry->id)->update(['description' => 'nothing happened']);
    }

    public function test_old_entries_can_be_cleaned_up(): void
    {
        Sanctum::actingAs($this->staff);
        $this->patchJson("/api/v1/parcels/{$this->parcel->id}", ['price' => 24000])->assertOk();

        $this->assertSame(1, DB::table('activity_log')->delete()); // the cleanup command needs this
    }

    public function test_automatic_changes_have_no_person(): void
    {
        Sanctum::actingAs($this->staff); // even with someone signed in elsewhere in the request
        app(\Modules\LandTracker\Services\PaymentLedger::class)
            ->recordCardPayment($this->parcel, $this->buyer, '10.00', 'txn-auto', now()->toDateString());

        $entry = Activity::where('properties->action', 'payment_recorded')->first();
        $this->assertNull($entry->causer_id);
        $this->assertSame('created', $entry->event);
        $this->assertStringStartsWith('Recorded payment of BZ$10.00 for Lot 1', $entry->description);
    }

    public function test_payments_and_outlines_are_logged(): void
    {
        Sanctum::actingAs($this->staff);
        $this->putJson("/api/v1/parcels/{$this->parcel->id}/outline",
            ['points' => [['x' => 1, 'y' => 1], ['x' => 5, 'y' => 1], ['x' => 5, 'y' => 5]]])->assertCreated();
        $this->postJson("/api/v1/parcels/{$this->parcel->id}/payments",
            ['amount' => 100, 'method' => 'cash', 'paid_on' => now()->toDateString()])->assertCreated();

        $actions = Activity::orderBy('id')->pluck('properties')->map(fn ($p) => $p['action'])->all();
        $this->assertContains('outline_edited', $actions);
        $this->assertContains('payment_recorded', $actions);
    }
}
