<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\Enquiry;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Tests\TestCase;

class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Parcel $parcel;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Faber Realty']);
        $land = Land::create(['org_id' => $this->org->id, 'name' => 'Cayo Ridge', 'place' => 'Cayo', 'is_public' => true]);
        $this->parcel = $land->parcels()->create(['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 3000]);
        $this->staff = $this->makeUser(['full_name' => 'S', 'email' => 's@example.test', 'role' => 'office_admin', 'status' => 'active']);
    }

    private function form(array $over = []): array
    {
        return array_merge(['parcel_id' => $this->parcel->id, 'name' => 'Ana Lopez', 'phone' => '+501 610-1234',
            'email' => 'Ana@Example.test', 'message' => 'Is this lot still available?'], $over);
    }

    public function test_public_can_send_enquiry_and_company_comes_from_parcel(): void
    {
        $this->postJson('/api/v1/public/enquiries', $this->form())->assertCreated()->assertJsonMissingPath('id');

        $e = Enquiry::first();
        $this->assertSame($this->org->id, $e->org_id);
        $this->assertSame('new', $e->status->value);
        $this->assertSame('ana@example.test', $e->email);
    }

    public function test_general_enquiry_needs_a_company_and_private_parcels_are_refused(): void
    {
        $this->postJson('/api/v1/public/enquiries', $this->form(['parcel_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('org_id');
        $this->postJson('/api/v1/public/enquiries', $this->form(['parcel_id' => null, 'org_id' => $this->org->id]))->assertCreated();

        $this->parcel->land->update(['is_public' => false]);
        $this->postJson('/api/v1/public/enquiries', $this->form())->assertUnprocessable()->assertJsonValidationErrors('parcel_id');
    }

    public function test_spam_is_quietly_dropped_and_rate_limited(): void
    {
        $this->postJson('/api/v1/public/enquiries', $this->form(['website' => 'http://spam']))->assertCreated();
        $this->assertSame(0, Enquiry::count());

        $this->postJson('/api/v1/public/enquiries', $this->form(['phone' => 'call me']))->assertUnprocessable();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/public/enquiries', $this->form());
        }
        $this->postJson('/api/v1/public/enquiries', $this->form())->assertTooManyRequests();
    }

    public function test_staff_follow_up_and_assign(): void
    {
        $this->postJson('/api/v1/public/enquiries', $this->form());
        $id = Enquiry::first()->id;
        $owner = $this->makeUser(['full_name' => 'O', 'email' => 'o@example.test', 'role' => 'owner', 'status' => 'active']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/enquiries')->assertForbidden();

        Sanctum::actingAs($this->staff);
        $this->patchJson("/api/v1/enquiries/{$id}", ['status' => 'viewing_booked', 'assigned_to' => $this->staff->id])
            ->assertOk()->assertJsonPath('status', 'viewing_booked')->assertJsonPath('assignee.id', $this->staff->id);
        $this->patchJson("/api/v1/enquiries/{$id}", ['assigned_to' => $owner->id])->assertUnprocessable();
        $this->patchJson("/api/v1/enquiries/{$id}", ['status' => 'reserved'])->assertUnprocessable();

        $this->getJson('/api/v1/enquiries?mine=1')->assertJsonPath('data.0.id', $id);
        $this->patchJson("/api/v1/enquiries/{$id}", ['status' => 'closed'])->assertOk();
        $this->getJson('/api/v1/enquiries')->assertJsonCount(0, 'data'); // closed hidden by default
    }

    public function test_reserve_creates_invited_owner_and_reserves_parcel(): void
    {
        $this->postJson('/api/v1/public/enquiries', $this->form());
        $id = Enquiry::first()->id;
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/enquiries/{$id}/reserve", ['hold_until' => now()->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('status', 'reserved')->assertJsonPath('buyer.email', 'ana@example.test');

        $buyer = User::where('email', 'ana@example.test')->first();
        $this->assertSame([['owner'], 'invited', '+501 610-1234'], [$buyer->companyRoleNames(), $buyer->status, $buyer->phone]);
        $this->assertSame('reserved', $this->parcel->fresh()->status->value);
        $this->assertSame($buyer->id, $this->parcel->fresh()->owner_id);
    }

    public function test_reserve_reuses_existing_account_and_fails_cleanly(): void
    {
        $existing = $this->makeUser(['full_name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'owner', 'status' => 'active']);
        $this->postJson('/api/v1/public/enquiries', $this->form());
        $this->postJson('/api/v1/public/enquiries', $this->form(['email' => null, 'phone' => '6100000']));
        [$a, $b] = Enquiry::orderBy('created_at')->pluck('id')->all();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/enquiries/{$b}/reserve", ['hold_until' => now()->addWeek()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson("/api/v1/enquiries/{$a}/reserve", ['hold_until' => now()->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('buyer_user_id', $existing->id);
        $this->assertSame(2, User::count()); // no duplicate account

        // Parcel already taken: nothing is created and the enquiry stays open
        $this->postJson("/api/v1/enquiries/{$b}/reserve", ['hold_until' => now()->addWeek()->toDateString(), 'email' => 'new@example.test'])
            ->assertUnprocessable();
        $this->assertNull(User::where('email', 'new@example.test')->first());
        $this->assertSame('new', Enquiry::find($b)->status->value);
    }
}
