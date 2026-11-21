<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Files shared with one owner, or with everyone on the public site
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('parcel_id')->nullable()->constrained('parcels')->nullOnDelete();
            $table->foreignUuid('shared_with')->nullable()->constrained('users')->cascadeOnDelete(); // empty means public
            $table->string('file_name');                  // name shown and used on download
            $table->string('file_path');                  // private storage
            $table->integer('size_bytes');
            $table->foreignUuid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['org_id', 'parcel_id']);
            $table->index('shared_with');
        });
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_size CHECK (size_bytes >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
