<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a buyer pays for a parcel: a deposit, then a set amount each month
        Schema::create('payment_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parcel_id')->unique()->constrained('parcels')->restrictOnDelete(); // one plan per parcel
            $table->foreignUuid('owner_id')->constrained('users')->restrictOnDelete();                  // the buyer
            $table->decimal('total_price', 12, 2);   // agreed price at signing
            $table->decimal('deposit', 12, 2);
            $table->decimal('monthly_amount', 12, 2); // 0 means pay in full
            $table->date('start_date');               // deposit due date
            $table->integer('grace_months')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE payment_plans ADD CONSTRAINT payment_plans_amounts CHECK (
            total_price > 0 AND deposit >= 0 AND deposit <= total_price AND monthly_amount >= 0 AND grace_months BETWEEN 0 AND 120)');

        // Each payment due under a plan. Created when the plan is saved.
        Schema::create('instalments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('payment_plans')->cascadeOnDelete();
            $table->integer('seq');                        // 0 is the deposit, then 1, 2, 3...
            $table->string('label');                       // Deposit, Month 1...
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0); // kept up to date from allocations

            $table->unique(['plan_id', 'seq']);
            $table->index('due_date');
        });
        DB::statement('ALTER TABLE instalments ADD CONSTRAINT instalments_amounts CHECK (
            amount > 0 AND amount_paid >= 0 AND amount_paid <= amount AND seq >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('instalments');
        Schema::dropIfExists('payment_plans');
    }
};
