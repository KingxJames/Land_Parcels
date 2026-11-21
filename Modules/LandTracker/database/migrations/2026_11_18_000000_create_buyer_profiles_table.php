<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Private buyer details kept for anti-money-laundering checks.
// Only office staff can read these rows.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_profiles', function (Blueprint $table) {
            // users.id is a big integer, so these links are too (not uuid).
            // Restrict delete: AML records must be kept, so a user with a
            // profile cannot be hard-deleted by accident.
            $table->foreignUuid('user_id')->primary()->constrained('users')->restrictOnDelete();

            $table->string('id_type')->nullable();          // social_security, passport, drivers_license, voter_id
            $table->text('id_number_encrypted')->nullable(); // encrypted by the model, never plain text
            $table->string('id_file_path')->nullable();      // path on the private "kyc" disk
            $table->text('home_address')->nullable();
            $table->string('source_of_funds')->nullable();   // savings, loan, sale_of_property, inheritance, business, other

            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_profiles');
    }
};
