<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\BuyerProfile;
use Modules\LandTracker\Models\Land;
use App\Models\Organization;
use Modules\LandTracker\Models\Parcel;
use Tests\TestCase;

class ParcelTest extends TestCase
{
    use RefreshDatabase;

    private Land $land;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Faber Realty']);
        $this->land = Land::create(['org_id' => $org->id, 'name' => 'Cayo Ridge', 'place' => 'San Ignacio', 'is_public' => true]);
        $this->staff = $this->user('office_admin');
    }

    private function user(string $role = 'owner'): User
    {
        static $n = 0;
        $n++;

        return $this->makeUser(['full_name' => "P$n", 'email' => "parcel$n@example.test", 'role' => $role, 'status' => 'active']);
    }

    private function parcel(array $over = []): Parcel
    {
        return $this->land->parcels()->create(array_merge(
            ['label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 25000], $over));
    }

    private function verifiedBuyer(): User
    {
        $b = $this->user();
        $p = BuyerProfile::create(['user_id' => $b->id, 'id_type' => 'passport', 'id_number_encrypted' => 'X1', 'id_file_path' => 'a.pdf']);
        $p->verified_by = $this->staff->id;
        $p->verified_at = now();
        $p->save();

        return $b;
    }

    public function test_staff_creates_house_and_land_parcels(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/lands/{$this->land->id}/parcels", [
            'label' => 'Beach Lot 2', 'kind' => 'house', 'size_sqft' => 6000, 'price' => '185000.50',
            'beds' => 3, 'baths' => 2.5, 'floor_area_sqft' => 1800, 'year_built' => 2019,
        ])->assertCreated()->assertJsonPath('status', 'available')->assertJsonPath('baths', '2.5');

        $this->postJson("/api/v1/lands/{$this->land->id}/parcels", [
            'label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 5000, 'price' => 25000, 'beds' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('beds');
    }

    public function test_labels_unique_per_land_and_status_cannot_be_set_directly(): void
    {
        $this->parcel();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/lands/{$this->land->id}/parcels", [
            'label' => 'Lot 1', 'kind' => 'land', 'size_sqft' => 1, 'price' => 1, 'status' => 'sold',
        ])->assertUnprocessable()->assertJsonValidationErrors(['label', 'status']);
    }

    public function test_reserve_release_and_sell_flow(): void
    {
        $parcel = $this->parcel();
        $buyer = $this->verifiedBuyer();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/parcels/{$parcel->id}/reserve", ['owner_id' => $buyer->id, 'hold_until' => now()->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('status', 'reserved')->assertJsonPath('owner.id', $buyer->id);

        // Can't reserve twice, can't sell to someone else
        $other = $this->verifiedBuyer();
        $this->postJson("/api/v1/parcels/{$parcel->id}/reserve", ['owner_id' => $other->id, 'hold_until' => now()->addDay()->toDateString()])
            ->assertUnprocessable();
        $this->postJson("/api/v1/parcels/{$parcel->id}/sell", ['owner_id' => $other->id])->assertUnprocessable();

        $this->postJson("/api/v1/parcels/{$parcel->id}/sell", ['price' => 24000])
            ->assertOk()->assertJsonPath('status', 'sold')->assertJsonPath('hold_until', null)->assertJsonPath('price', '24000.00');

        $this->postJson("/api/v1/parcels/{$parcel->id}/release")->assertUnprocessable();
        $this->deleteJson("/api/v1/parcels/{$parcel->id}")->assertConflict();
    }

    public function test_sale_needs_verified_buyer(): void
    {
        $parcel = $this->parcel();
        $buyer = $this->user();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/v1/parcels/{$parcel->id}/sell", ['owner_id' => $buyer->id])
            ->assertUnprocessable()->assertJsonValidationErrors('owner_id');
        $this->assertSame('available', $parcel->fresh()->status->value);
    }

    public function test_release_puts_parcel_back_on_sale(): void
    {
        $parcel = $this->parcel();
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/v1/parcels/{$parcel->id}/reserve", ['owner_id' => $this->user()->id, 'hold_until' => now()->toDateString()])->assertOk();

        $this->postJson("/api/v1/parcels/{$parcel->id}/release")
            ->assertOk()->assertJsonPath('status', 'available')->assertJsonPath('owner_id', null);
    }

    public function test_expired_holds_are_released_by_command(): void
    {
        $buyer = $this->user();
        $expired = $this->parcel(['label' => 'Lot 1']);
        $today = $this->parcel(['label' => 'Lot 2']);
        DB::table('parcels')->where('id', $expired->id)->update(['status' => 'reserved', 'owner_id' => $buyer->id, 'hold_until' => now()->subDay()->toDateString()]);
        DB::table('parcels')->where('id', $today->id)->update(['status' => 'reserved', 'owner_id' => $buyer->id, 'hold_until' => now()->toDateString()]);

        $this->artisan('parcels:release-expired')->expectsOutput('Released 1 parcel(s).')->assertSuccessful();

        $this->assertSame('available', $expired->fresh()->status->value);
        $this->assertSame('reserved', $today->fresh()->status->value);
    }

    public function test_public_list_hides_owner_and_private_lands(): void
    {
        $p = $this->parcel(['label' => 'Lot 10']);
        $this->parcel(['label' => 'Lot 2']);
        DB::table('parcels')->where('id', $p->id)->update(['status' => 'reserved', 'owner_id' => $this->user()->id, 'hold_until' => now()->toDateString()]);

        $this->getJson("/api/v1/public/lands/{$this->land->id}/parcels")
            ->assertOk()
            ->assertJsonPath('0.label', 'Lot 2')   // natural order
            ->assertJsonPath('1.label', 'Lot 10')
            ->assertJsonPath('1.status', 'reserved')
            ->assertJsonMissingPath('1.owner_id')
            ->assertJsonMissingPath('1.hold_until');

        $this->land->update(['is_public' => false]);
        $this->getJson("/api/v1/public/lands/{$this->land->id}/parcels")->assertNotFound();
    }

    public function test_owner_sees_only_their_parcels_and_cannot_manage(): void
    {
        $owner = $this->user();
        $mine = $this->parcel(['label' => 'Lot 1']);
        $this->parcel(['label' => 'Lot 2']);
        DB::table('parcels')->where('id', $mine->id)->update(['status' => 'reserved', 'owner_id' => $owner->id, 'hold_until' => now()->toDateString()]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/my/parcels')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine->id);
        $this->getJson("/api/v1/lands/{$this->land->id}/parcels")->assertForbidden();
        $this->postJson("/api/v1/parcels/{$mine->id}/release")->assertForbidden();
    }

    public function test_database_rejects_inconsistent_status(): void
    {
        $p = $this->parcel();
        $this->expectException(QueryException::class);
        DB::table('parcels')->where('id', $p->id)->update(['status' => 'sold']); // sold with no owner
    }

    public function test_land_with_parcels_cannot_be_deleted(): void
    {
        $this->parcel();
        Sanctum::actingAs($this->staff);
        $this->deleteJson("/api/v1/lands/{$this->land->id}")->assertConflict();
    }
}
