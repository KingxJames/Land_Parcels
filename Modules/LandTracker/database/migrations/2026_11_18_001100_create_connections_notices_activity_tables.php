<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company's linked E-Kyash merchant or card gateway. Keys are stored encrypted.
        Schema::create('payment_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->restrictOnDelete();
            $table->string('provider');                           // ekyash, card_gateway or digiwallet
            $table->string('merchant_id');                        // E-Kyash SID or gateway merchant id
            $table->text('api_key_encrypted');
            $table->text('callback_secret_encrypted')->nullable(); // checks payment notices are genuine
            $table->string('mode');                               // test or live
            $table->timestampTz('connected_at');

            $table->unique(['org_id', 'provider']); // one link per provider per company
        });
        DB::statement("ALTER TABLE payment_connections ADD CONSTRAINT payment_connections_values CHECK (
            provider IN ('ekyash', 'card_gateway', 'digiwallet') AND mode IN ('test', 'live'))");

        // Every message E-Kyash or the card gateway sends back
        Schema::create('payment_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained('payment_connections')->restrictOnDelete();
            $table->string('external_invoice_id');
            $table->string('status');                      // approved, declined...
            $table->jsonb('payload');                      // the full message, kept for checking
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->timestampTz('received_at');

            $table->index(['connection_id', 'external_invoice_id']);
        });

        // The activity log is spatie/laravel-activitylog's table (see the Auth module migrations)
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notices');
        Schema::dropIfExists('payment_connections');
    }
};
