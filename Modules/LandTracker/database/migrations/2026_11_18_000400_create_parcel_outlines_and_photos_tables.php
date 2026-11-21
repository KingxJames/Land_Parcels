<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shape drawn on the site plan. Corner points are percentages of the photo.
        Schema::create('parcel_outlines', function (Blueprint $table) {
            $table->foreignUuid('parcel_id')->primary()->constrained('parcels')->cascadeOnDelete(); // one outline per parcel
            $table->jsonb('points');                                                                  // [{"x":40.4,"y":14}, ...]
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();     
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement("ALTER TABLE parcel_outlines ADD CONSTRAINT parcel_outlines_points_shape
            CHECK (jsonb_typeof(points) = 'array' AND jsonb_array_length(points) BETWEEN 3 AND 500)");

        // Photos shown on a parcel's public page
        Schema::create('parcel_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('parcel_id')->constrained('parcels')->cascadeOnDelete();
            $table->string('file_path');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['parcel_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_photos');
        Schema::dropIfExists('parcel_outlines');
    }
};
