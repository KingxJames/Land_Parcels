<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    // Step 1: send the person to Google. ?org=<slug> picks the company when one
    // email has accounts in several; it travels through Google encrypted in "state".
    public function redirect(Request $request)
    {
        $driver = Socialite::driver('google')->stateless()->with(['prompt' => 'select_account']);

        if ($slug = $request->query('org')) {
            $driver->with(['prompt' => 'select_account', 'state' => Crypt::encryptString((string) $slug)]);
        }

        return $driver->redirect();
    }

    // Step 2: Google sends them back here
    public function callback(Request $request)
    {
        $front = config('app.frontend_url');

        try {
            $g = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::warning('Google login failed', ['error' => $e->getMessage()]);
            return redirect($front . '/login?error=google_failed');
        }

        // Only trust addresses Google has confirmed belong to this person
        if (!($g->user['email_verified'] ?? false)) {
            return redirect($front . '/login?error=google_failed');
        }

        $slug = null;
        if ($state = $request->query('state')) {
            try {
                $slug = Crypt::decryptString($state);
            } catch (\Throwable) {
                return redirect($front . '/login?error=google_failed');
            }
        }

        // Email is unique within a company, so the same person may have several accounts
        $matches = User::with('organization')
            ->where('email', strtolower($g->getEmail()))
            ->when($slug, fn ($q) => $q->whereHas('organization', fn ($o) => $o->where('slug', $slug)))
            ->get();

        if ($matches->isEmpty()) {
            return redirect($front . '/login?error=not_invited');
        }
        if ($matches->count() > 1) {
            return redirect($front . '/login?error=choose_company');
        }

        $user = $matches->first();
        if ($user->status === 'switched_off') {
            return redirect($front . '/login?error=switched_off');
        }
        if (! $user->isPlatformAdmin() && $user->organization?->isSuspended()) {
            return redirect($front . '/login?error=company_suspended');
        }

        $user->update([
            'google_id' => $g->getId(),
            'avatar' => $g->getAvatar(),
            'full_name' => $user->full_name ?: $g->getName(),
            'status' => 'active',
            'last_sign_in_at' => now(),
        ]);

        $token = $user->createToken('web')->plainTextToken;

        return redirect($front . '/auth/callback?token=' . urlencode($token));
    }

    // Who is signed in (the frontend calls this after login)
    public function me(Request $request)
    {
        $u = $request->user()->load('organization');

        return [
            ...$u->only(['id', 'full_name', 'email', 'phone', 'avatar', 'status', 'org_id', 'is_platform_admin', 'last_sign_in_at']),
            'organization' => $u->organization?->only(['id', 'name', 'slug', 'currency', 'theme', 'status']),
            // For menus: what this person may do in their company. "*" means everything (platform owner).
            'roles' => $u->getRoleNames()->values(),
            'permissions' => $u->isPlatformAdmin() ? ['*'] : $u->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }

    // Sign out: deletes this token
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out']);
    }
}