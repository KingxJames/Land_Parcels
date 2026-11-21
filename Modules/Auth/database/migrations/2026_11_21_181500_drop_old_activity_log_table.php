<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The first activity log was a home-made table with the same name. Databases that
// already have it drop it here, so spatie/laravel-activitylog can create its own next.
// Fresh databases never had it, so this does nothing there.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'entity_type')) {
            Schema::drop('activity_log');
        }
        DB::unprepared('DROP FUNCTION IF EXISTS activity_log_append_only() CASCADE');
    }

    public function down(): void
    {
        // Not recreated: the package's table replaces it
    }
};
