<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use App\Support\DefaultRoles;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // A user in the test's company (the first one made in the test, or a new one).
    // 'role' gives them that role in their company. Pass 'is_platform_admin' => true
    // for the platform owner, who has no company and needs no role.
    protected function makeUser(array $attrs): User
    {
        $platform = (bool) ($attrs['is_platform_admin'] ?? false);
        $role = $attrs['role'] ?? null;
        unset($attrs['is_platform_admin'], $attrs['role']);

        if (isset($attrs['name'])) {
            $attrs['full_name'] = $attrs['name'];
            unset($attrs['name']);
        }
        if (! $platform && ! array_key_exists('org_id', $attrs)) {
            $attrs['org_id'] = Organization::orderBy('created_at')->value('id')
                ?? Organization::create(['name' => 'Test Company'])->id;
        }

        $user = new User($attrs);
        $user->forceFill(['is_platform_admin' => $platform])->save();

        if ($role && ! $platform) {
            DefaultRoles::assign($user, $role);
        }

        return $user;
    }
}
