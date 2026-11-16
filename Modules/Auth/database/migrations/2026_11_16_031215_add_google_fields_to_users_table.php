<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('avatar')->nullable()->after('google_id');
            $table->enum('role', ['super_admin', 'office_admin', 'owner'])->default('owner')->after('avatar');
            $table->enum('status', ['invited', 'active', 'switched_off'])->default('invited')->after('role');
            $table->string('phone')->nullable()->after('status');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'avatar', 'role', 'status', 'phone']);
        });
    }
};