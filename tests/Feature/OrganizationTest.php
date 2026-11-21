<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function platform(): User
    {
        return $this->makeUser(['full_name' => 'P', 'email' => 'p@example.test', 'role' => 'super_admin', 'status' => 'active', 'is_platform_admin' => true]);
    }

    public function test_slug_and_receipt_prefix_are_filled_in_and_unique(): void
    {
        $a = Organization::create(['name' => 'Cayo Ridge Estates']);
        $b = Organization::create(['name' => 'Cayo Ridge Estates']);

        $this->assertSame(['cayo-ridge-estates', 'CRE-'], [$a->slug, $a->receipt_prefix]);
        $this->assertSame(['cayo-ridge-estates-2', 'CRE2-'], [$b->slug, $b->receipt_prefix]);
        $this->assertSame(['BZD', 'trial', 3], [$a->fresh()->currency, $a->fresh()->status, $a->fresh()->reminder_days_before]);
    }

    public function test_platform_owner_creates_company_with_first_admin(): void
    {
        Sanctum::actingAs($this->platform());

        $id = $this->postJson('/api/v1/platform/orgs', [
            'name' => 'Island Estates', 'currency' => 'USD', 'admin_email' => 'Boss@Island.test', 'admin_name' => 'Boss',
        ])->assertCreated()->assertJsonPath('slug', 'island-estates')->assertJsonPath('users_count', 1)->json('id');

        $admin = User::where('email', 'boss@island.test')->first();
        $this->assertSame([$id, ['super_admin'], 'invited'], [$admin->org_id, $admin->companyRoleNames(), $admin->status]);

        $this->patchJson("/api/v1/platform/orgs/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('activity_log', ['properties->action' => 'organization_status_changed', 'org_id' => $id]);
    }

    public function test_company_admin_edits_own_profile_but_not_status_or_others(): void
    {
        $org = Organization::create(['name' => 'Faber Realty']);
        $admin = $this->makeUser(['full_name' => 'A', 'email' => 'a@example.test', 'role' => 'super_admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/org', ['whatsapp' => '+501 600-0000', 'reminder_days_before' => 5, 'receipt_prefix' => 'FAB-', 'status' => 'active'])
            ->assertOk()->assertJsonPath('whatsapp', '+501 600-0000')->assertJsonPath('receipt_prefix', 'FAB-')
            ->assertJsonPath('status', 'trial'); // ignored

        $this->patchJson('/api/v1/org', ['receipt_prefix' => 'R1'])->assertUnprocessable(); // must end in a letter or "-"
        $this->getJson('/api/v1/platform/orgs')->assertForbidden();

        // Office admins can look but not edit
        Sanctum::actingAs($this->makeUser(['full_name' => 'O', 'email' => 'o@example.test', 'role' => 'office_admin', 'status' => 'active']));
        $this->getJson('/api/v1/org')->assertOk()->assertJsonPath('id', $org->id);
        $this->patchJson('/api/v1/org', ['phone' => '1'])->assertForbidden();
    }

    public function test_logo_upload_and_public_profile(): void
    {
        Storage::fake('local');
        $org = Organization::create(['name' => 'Faber Realty', 'phone' => '+501 222-3333']);
        Sanctum::actingAs($this->makeUser(['full_name' => 'A', 'email' => 'a@example.test', 'role' => 'super_admin', 'status' => 'active']));

        $this->post('/api/v1/org/logo', ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/public/orgs/{$org->slug}")
            ->assertOk()->assertJsonPath('phone', '+501 222-3333')->assertJsonPath('currency', 'BZD')
            ->assertJsonMissingPath('receipt_prefix')->assertJsonMissingPath('status');
        $this->get("/api/v1/public/orgs/{$org->slug}/logo")->assertOk();
    }

    public function test_receipts_use_each_companys_prefix_and_counter(): void
    {
        foreach (['Alpha Lands' => 'AL-1001', 'Beta Homes' => 'BH-1001'] as $name => $expected) {
            $org = Organization::create(['name' => $name]);
            $land = \Modules\LandTracker\Models\Land::create(['org_id' => $org->id, 'name' => 'L', 'place' => 'P']);
            $parcel = $land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 1, 'price' => 100]);
            $buyer = $this->makeUser(['full_name' => 'B', 'email' => "b@{$org->slug}.test", 'role' => 'owner', 'status' => 'active', 'org_id' => $org->id]);
            \Illuminate\Support\Facades\DB::table('parcels')->where('id', $parcel->id)
                ->update(['status' => 'reserved', 'owner_id' => $buyer->id, 'hold_until' => now()->addWeek()->toDateString()]);

            $p = app(\Modules\LandTracker\Services\PaymentLedger::class)
                ->recordCardPayment($parcel, $buyer, '10.00', "txn-{$org->slug}", now()->toDateString());
            $this->assertSame($expected, $p->receipt->receipt_no);
        }
    }
}
