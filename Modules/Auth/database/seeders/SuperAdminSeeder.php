<?php

namespace Modules\Auth\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'jaafaber@gmail.com'],
            ['name' => 'James Faber', 'role' => 'super_admin', 'status' => 'invited']
        );
    }
}