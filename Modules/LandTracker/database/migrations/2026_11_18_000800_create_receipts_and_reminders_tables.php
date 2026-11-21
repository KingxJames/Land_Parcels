<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free receipt numbers: one row per company, locked while a number is taken
        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->foreignUuid('id')->primary()->constrained('organizations')->cascadeOnDelete(); // the company
            $table->bigInteger('next_no');
        });

        // The numbered receipt made for every confirmed payment
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->unique()->constrained('payments')->restrictOnDelete(); // one receipt per payment
            $table->string('receipt_no')->unique();     // company prefix + number, e.g. R-1012
            $table->decimal('balance_after', 12, 2);    // balance left on the parcel
            $table->string('pdf_path')->nullable();
            $table->string('sent_via')->nullable();     // whatsapp or email
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('issued_at');
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_sent CHECK (
            (sent_via IS NULL AND sent_at IS NULL) OR (sent_via IN ('whatsapp', 'email') AND sent_at IS NOT NULL))");
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_balance CHECK (balance_after >= 0)');

        // Each payment reminder sent to an owner
        Schema::create('reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('instalment_id')->constrained('instalments')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();  
            $table->string('channel');                                                 // whatsapp, sms or email
            $table->text('message');
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete(); // empty when automatic
            $table->timestampTz('sent_at');

            $table->index(['instalment_id', 'sent_at']);
        });
        DB::statement("ALTER TABLE reminders ADD CONSTRAINT reminders_channel CHECK (channel IN ('whatsapp', 'sms', 'email'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('receipt_counters');
    }
};
