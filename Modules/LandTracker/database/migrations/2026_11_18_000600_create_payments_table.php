<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Money received or reported. Online card payments arrive confirmed; transfers wait for the office.
// Written in SQL for the native Postgres enum types.
return new class extends Migration
{
    public function up(): void
    {
        // migrate:fresh drops tables but not types, so clear leftovers first
        DB::statement('DROP TYPE IF EXISTS pay_status');
        DB::statement('DROP TYPE IF EXISTS pay_source');
        DB::statement('DROP TYPE IF EXISTS pay_method');

        DB::statement("CREATE TYPE pay_method AS ENUM ('cash', 'bank_transfer', 'ekyash', 'digiwallet', 'card')");
        DB::statement("CREATE TYPE pay_source AS ENUM ('office', 'online')");
        DB::statement("CREATE TYPE pay_status AS ENUM ('pending', 'confirmed', 'rejected')");

        DB::statement(<<<'SQL'
            CREATE TABLE payments (
                id              uuid PRIMARY KEY,
                parcel_id       uuid NOT NULL REFERENCES parcels(id) ON DELETE RESTRICT,
                owner_id        uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,   -- who paid
                amount          numeric(12,2) NOT NULL CHECK (amount > 0),
                method          pay_method NOT NULL,
                source          pay_source NOT NULL,
                status          pay_status NOT NULL DEFAULT 'pending',
                reference       varchar(100),                                              -- transaction number from the app or bank
                provider_txn_id varchar(150),                                              -- E-Kyash or card gateway id
                paid_on         date NOT NULL,
                recorded_by     uuid REFERENCES users(id) ON DELETE RESTRICT,            -- staff; empty for online
                confirmed_by    uuid REFERENCES users(id) ON DELETE RESTRICT,            -- staff who confirmed or rejected
                confirmed_at    timestamptz,
                created_at      timestamptz,
                updated_at      timestamptz,

                -- Office payments name the staff member; online ones don't
                CONSTRAINT payments_source_recorder CHECK (
                    (source = 'office' AND recorded_by IS NOT NULL) OR
                    (source = 'online' AND recorded_by IS NULL)
                ),
                -- Pending has no decision yet; confirmed and rejected have a time
                CONSTRAINT payments_status_decision CHECK (
                    (status = 'pending' AND confirmed_by IS NULL AND confirmed_at IS NULL) OR
                    (status <> 'pending' AND confirmed_at IS NOT NULL)
                ),
                -- Only the card gateway pays by card, and only online
                CONSTRAINT payments_card_online CHECK (method <> 'card' OR source = 'online')
            )
        SQL);

        // The same gateway transaction can never be recorded twice
        DB::statement('CREATE UNIQUE INDEX payments_provider_txn_unique ON payments (method, provider_txn_id) WHERE provider_txn_id IS NOT NULL');
        DB::statement('CREATE INDEX payments_parcel_index ON payments (parcel_id, paid_on)');
        DB::statement('CREATE INDEX payments_owner_index ON payments (owner_id)');
        DB::statement("CREATE INDEX payments_pending_index ON payments (created_at) WHERE status = 'pending'");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS payments');
        DB::statement('DROP TYPE IF EXISTS pay_status');
        DB::statement('DROP TYPE IF EXISTS pay_source');
        DB::statement('DROP TYPE IF EXISTS pay_method');
    }
};
