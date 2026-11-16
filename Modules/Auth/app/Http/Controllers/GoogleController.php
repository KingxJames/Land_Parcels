<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    // Step 1: send the person to Google
    public function redirect()
    {
        return Socialite::driver('google')
            ->stateless()
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    // Step 2: Google sends them back here
    public function callback()
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

        $user = User::where('email', strtolower($g->getEmail()))->first();

        if (!$user) {
            return redirect($front . '/login?error=not_invited');
        }
        if ($user->status === 'switched_off') {
            return redirect($front . '/login?error=switched_off');
        }

        $user->update([
            'google_id' => $g->getId(),
            'avatar' => $g->getAvatar(),
            'name' => $user->name ?: $g->getName(),
            'status' => 'active',
        ]);

        $token = $user->createToken('web')->plainTextToken;

        return redirect($front . '/auth/callback?token=' . urlencode($token));
    }

    // Who is signed in (the frontend calls this after login)
    public function me(Request $request)
    {
        return $request->user()->only(['id', 'name', 'email', 'avatar', 'role', 'status']);
    }

    // Sign out: deletes this token
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Signed out']);
    }
}