<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function googleReturns(string $email): void
    {
        $g = (new GoogleUser)->setRaw(['email_verified' => true])
            ->map(['id' => 'g-123', 'email' => $email, 'name' => 'Google Name', 'avatar' => 'https://example.test/a.png']);

        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($g);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_single_account_signs_in_and_records_time(): void
    {
        $u = $this->makeUser(['full_name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'owner', 'status' => 'invited']);
        $this->googleReturns('Ana@Example.test');

        $res = $this->get('/api/auth/google/callback');
        $this->assertStringContainsString('/auth/callback?token=', $res->headers->get('Location'));
        $u->refresh();
        $this->assertSame('active', $u->status);
        $this->assertNotNull($u->last_sign_in_at);
    }

    public function test_two_companies_need_a_choice_which_state_provides(): void
    {
        $a = Organization::create(['name' => 'Alpha Lands']);
        $b = Organization::create(['name' => 'Beta Homes']);
        $this->makeUser(['full_name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'owner', 'org_id' => $a->id]);
        $inB = $this->makeUser(['full_name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'owner', 'org_id' => $b->id]);
        $this->googleReturns('ana@example.test');

        $this->get('/api/auth/google/callback')->assertRedirectContains('error=choose_company');

        $this->get('/api/auth/google/callback?state=' . urlencode(Crypt::encryptString($b->slug)))->assertRedirectContains('token=');
        $this->assertNotNull($inB->fresh()->last_sign_in_at);

        $this->get('/api/auth/google/callback?state=tampered')->assertRedirectContains('error=google_failed');
    }

    public function test_suspended_company_cannot_sign_in(): void
    {
        $org = Organization::create(['name' => 'Gone Ltd']);
        \Illuminate\Support\Facades\DB::table('organizations')->where('id', $org->id)->update(['status' => 'suspended']);
        $this->makeUser(['full_name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'owner']);
        $this->googleReturns('ana@example.test');

        $this->get('/api/auth/google/callback')->assertRedirectContains('error=company_suspended');
    }

    public function test_me_shows_company(): void
    {
        $org = Organization::create(['name' => 'Faber Realty']);
        \Laravel\Sanctum\Sanctum::actingAs($this->makeUser(['full_name' => 'Ana', 'email' => 'ana@example.test', 'role' => 'owner', 'status' => 'active']));

        $this->getJson('/api/me')->assertOk()->assertJsonPath('full_name', 'Ana')
            ->assertJsonPath('organization.slug', 'faber-realty')->assertJsonPath('is_platform_admin', false)
            ->assertJsonPath('roles', ['owner'])->assertJsonPath('permissions', []);
    }

    public function test_me_lists_permissions_for_menus(): void
    {
        Organization::create(['name' => 'Faber Realty']);
        \Laravel\Sanctum\Sanctum::actingAs($this->makeUser(['full_name' => 'Cash', 'email' => 'c@example.test', 'role' => 'cashier', 'status' => 'active']));
        $this->getJson('/api/me')->assertJsonPath('roles', ['cashier'])->assertJsonPath('permissions', ['payments.record', 'receipts.send']);

        \Laravel\Sanctum\Sanctum::actingAs($this->makeUser(['full_name' => 'P', 'email' => 'p@example.test', 'role' => 'super_admin', 'status' => 'active', 'is_platform_admin' => true]));
        $this->getJson('/api/me')->assertJsonPath('permissions', ['*']);
    }
}
