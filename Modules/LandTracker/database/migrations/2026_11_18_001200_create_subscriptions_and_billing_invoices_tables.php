<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each company pays you, using the per-parcel price
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('org_id')->primary()->constrained('organizations')->restrictOnDelete();
            $table->decimal('price_per_parcel', 8, 2);
            $table->decimal('minimum_monthly', 8, 2);
            $table->decimal('monthly_cap', 8, 2)->nullable();
            $table->string('billing_cycle');   // monthly or yearly
            $table->string('status');          // trial, active, past_due or cancelled
            $table->date('started_at');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_values CHECK (
            price_per_parcel >= 0 AND minimum_monthly >= 0
            AND (monthly_cap IS NULL OR monthly_cap >= minimum_monthly)
            AND billing_cycle IN ('monthly', 'yearly')
            AND status IN ('trial', 'active', 'past_due', 'cancelled'))");

        // Your monthly bill to each company
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->restrictOnDelete();
            $table->date('period_start');
            $table->integer('billable_parcels');  // for sale, reserved, or still being paid off
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('open'); // open, paid or overdue
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['org_id', 'period_start']); // never bill a period twice
        });
        DB::statement("ALTER TABLE billing_invoices ADD CONSTRAINT billing_invoices_values CHECK (
            billable_parcels >= 0 AND amount >= 0
            AND status IN ('open', 'paid', 'overdue')
            AND ((status = 'paid') = (paid_at IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('subscriptions');
    }
};
