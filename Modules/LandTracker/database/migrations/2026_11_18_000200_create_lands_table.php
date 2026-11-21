<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A piece of land being sold in parcels, such as Island Estates or Cayo Ridge
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Restrict: deleting an organization must not silently wipe its lands
            $table->foreignUuid('org_id')->constrained('organizations')->restrictOnDelete();
            $table->string('name');
            $table->string('place');
            $table->string('site_plan_path')->nullable();          // aerial photo or plan on the private disk
            $table->decimal('plan_width_ft', 12, 2)->nullable();   // real width the photo covers
            $table->decimal('plan_height_ft', 12, 2)->nullable();
            $table->boolean('is_public')->default(false);          // show on the public website
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['org_id', 'sort_order']);
            $table->index(['is_public', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lands');
    }
};
