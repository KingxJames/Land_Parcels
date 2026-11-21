<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Messages from the public website about a parcel.
// Written in SQL for the native Postgres enum type.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS enquiry_status'); // migrate:fresh leaves types behind
        DB::statement("CREATE TYPE enquiry_status AS ENUM ('new', 'contacted', 'viewing_booked', 'reserved', 'closed')");

        DB::statement(<<<'SQL'
            CREATE TABLE enquiries (
                id            uuid PRIMARY KEY,
                org_id        uuid NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
                parcel_id     uuid REFERENCES parcels(id) ON DELETE SET NULL,
                name          varchar(150) NOT NULL,
                phone         varchar(40) NOT NULL,
                email         varchar(255),
                message       text,
                status        enquiry_status NOT NULL DEFAULT 'new',
                assigned_to   uuid REFERENCES users(id) ON DELETE SET NULL,   -- staff following up
                buyer_user_id uuid REFERENCES users(id) ON DELETE SET NULL,   -- owner account created when reserved
                created_at    timestamptz,
                updated_at    timestamptz,

                CONSTRAINT enquiries_reserved_buyer CHECK (status <> 'reserved' OR buyer_user_id IS NOT NULL)
            )
        SQL);

        DB::statement("CREATE INDEX enquiries_open_index ON enquiries (org_id, status, created_at) WHERE status <> 'closed'");
        DB::statement('CREATE INDEX enquiries_parcel_index ON enquiries (parcel_id)');
        DB::statement('CREATE INDEX enquiries_assigned_index ON enquiries (assigned_to)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS enquiries');
        DB::statement('DROP TYPE IF EXISTS enquiry_status');
    }
};
