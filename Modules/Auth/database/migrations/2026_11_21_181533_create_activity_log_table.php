<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateActivityLogTable extends Migration
{
    public function up()
    {
        Schema::connection(config('activitylog.database_connection'))->create(config('activitylog.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            // Added: the company, so each company only sees its own log (empty for platform-wide entries)
            $table->foreignUuid('org_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('log_name')->nullable();
            $table->text('description');
            // uuid, not bigint: parcels, payments, users and companies all have uuid ids
            $table->nullableUuidMorphs('subject', 'subject');
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index('log_name');
            $table->index(['org_id', 'created_at']);
        });

        // History can be cleaned up (activitylog:clean deletes old entries) but never rewritten
        \Illuminate\Support\Facades\DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_log_no_edits() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'activity_log entries cannot be changed';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_log_no_edits BEFORE UPDATE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION activity_log_no_edits();
        SQL);
    }

    public function down()
    {
        Schema::connection(config('activitylog.database_connection'))->dropIfExists(config('activitylog.table_name'));
        \Illuminate\Support\Facades\DB::unprepared('DROP FUNCTION IF EXISTS activity_log_no_edits()');
    }
}
