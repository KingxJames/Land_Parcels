<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Which instalments a payment paid off. One payment can cover several months.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            // Restrict: a plan whose instalments have money on them can't be wiped by a cascade
            $table->foreignUuid('instalment_id')->constrained('instalments')->restrictOnDelete();
            $table->decimal('amount', 12, 2);

            $table->primary(['payment_id', 'instalment_id']);
            $table->index('instalment_id');
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
