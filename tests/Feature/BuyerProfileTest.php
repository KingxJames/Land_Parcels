<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\LandTracker\Models\BuyerProfile;
use Tests\TestCase;

class BuyerProfileTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role = 'owner'): User
    {
        static $n = 0;
        $n++;

        return $this->makeUser([
            'full_name' => "Person $n", 'email' => "person$n@example.test",
            'role' => $role, 'status' => 'active',
        ]);
    }

    private function fullProfile(User $buyer): BuyerProfile
    {
        return BuyerProfile::create([
            'user_id' => $buyer->id, 'id_type' => 'passport', 'id_number_encrypted' => 'P12345678',
            'id_file_path' => 'buyers/x.pdf', 'home_address' => '1 Main St', 'source_of_funds' => 'savings',
        ]);
    }

    public function test_owner_cannot_read_profiles(): void
    {
        $buyer = $this->person();
        $this->fullProfile($buyer);
        Sanctum::actingAs($buyer);

        $this->getJson('/api/v1/buyer-profiles')->assertForbidden();
        $this->getJson("/api/v1/buyer-profiles/{$buyer->id}")->assertForbidden();
    }

    public function test_guest_is_rejected(): void
    {
        $this->getJson('/api/v1/buyer-profiles')->assertUnauthorized();
    }

    public function test_staff_can_save_and_id_number_is_encrypted_and_masked(): void
    {
        Storage::fake('kyc');
        $buyer = $this->person();
        Sanctum::actingAs($this->person('office_admin'));

        $res = $this->post("/api/v1/buyer-profiles/{$buyer->id}", [
            'id_type' => 'passport', 'id_number' => 'P12345678',
            'id_file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            'home_address' => '1 Main St', 'source_of_funds' => 'savings',
        ], ['Accept' => 'application/json']);

        $res->assertCreated()
            ->assertJsonPath('id_number_masked', '•••••5678')
            ->assertJsonPath('has_id_file', true)
            ->assertJsonMissingPath('id_number_encrypted')
            ->assertJsonMissingPath('id_file_path');

        $raw = DB::table('buyer_profiles')->where('user_id', $buyer->id)->value('id_number_encrypted');
        $this->assertNotSame('P12345678', $raw);
        $this->assertStringNotContainsString('P12345678', $raw);

        Storage::disk('kyc')->assertExists(BuyerProfile::find($buyer->id)->id_file_path);
    }

    public function test_bad_input_is_rejected(): void
    {
        $buyer = $this->person();
        Sanctum::actingAs($this->person('office_admin'));

        $this->postJson("/api/v1/buyer-profiles/{$buyer->id}", ['id_type' => 'library_card', 'source_of_funds' => 'crime'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id_type', 'id_number', 'source_of_funds']);
    }

    public function test_verify_needs_complete_profile_and_records_who(): void
    {
        $buyer = $this->person();
        $staff = $this->person('office_admin');
        Sanctum::actingAs($staff);

        BuyerProfile::create(['user_id' => $buyer->id, 'home_address' => '1 Main St']);
        $this->postJson("/api/v1/buyer-profiles/{$buyer->id}/verify")->assertUnprocessable();

        $buyer2 = $this->person();
        $this->fullProfile($buyer2);
        $this->postJson("/api/v1/buyer-profiles/{$buyer2->id}/verify")
            ->assertOk()
            ->assertJsonPath('is_verified', true)
            ->assertJsonPath('verifier.id', $staff->id);
    }

    public function test_staff_cannot_verify_themselves(): void
    {
        $staff = $this->person('office_admin');
        $this->fullProfile($staff);
        Sanctum::actingAs($staff);

        $this->postJson("/api/v1/buyer-profiles/{$staff->id}/verify")->assertForbidden();
    }

    public function test_editing_checked_details_clears_verification(): void
    {
        $buyer = $this->person();
        $staff = $this->person('office_admin');
        $profile = $this->fullProfile($buyer);
        $profile->verified_by = $staff->id;
        $profile->verified_at = now();
        $profile->save();
        $this->assertTrue($profile->fresh()->is_verified);

        Sanctum::actingAs($staff);
        $this->postJson("/api/v1/buyer-profiles/{$buyer->id}", ['home_address' => '2 New Rd'])->assertOk()
            ->assertJsonPath('is_verified', false);
    }

    public function test_user_with_profile_cannot_be_hard_deleted(): void
    {
        $buyer = $this->person();
        $this->fullProfile($buyer);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $buyer->delete();
    }
}
