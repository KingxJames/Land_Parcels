<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Payment methods are now each company's own list (organizations.settings), so the
// fixed pay_method type becomes plain text. Existing values stay as they are.
return new class extends Migration
{
    public function up(): void
    {
        // This rule compares against the old type, so it's rebuilt around the change
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_card_online');
        DB::statement('ALTER TABLE payments ALTER COLUMN method TYPE varchar(40) USING method::text');
        DB::statement('DROP TYPE IF EXISTS pay_method');
        // Only the card gateway pays by card, and only online
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_card_online CHECK (method <> 'card' OR source = 'online')");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_format CHECK (method ~ '^[a-z0-9_]{1,40}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_format');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_card_online');
        DB::statement("CREATE TYPE pay_method AS ENUM ('cash', 'bank_transfer', 'ekyash', 'digiwallet', 'card')");
        DB::statement('ALTER TABLE payments ALTER COLUMN method TYPE pay_method USING method::pay_method');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_card_online CHECK (method <> 'card' OR source = 'online')");
    }
};
