<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// One lot or home for sale. Status and owner change as it is reserved and sold.
// Written in SQL because Laravel can't make native Postgres enum types.
return new class extends Migration
{
    public function up(): void
    {
        // migrate:fresh drops tables but not types, so clear leftovers first.
        // Safe: nothing else uses these types, and the parcels table doesn't exist yet.
        DB::statement('DROP TYPE IF EXISTS parcel_status');
        DB::statement('DROP TYPE IF EXISTS parcel_kind');

        DB::statement("CREATE TYPE parcel_kind AS ENUM ('land', 'house')");
        DB::statement("CREATE TYPE parcel_status AS ENUM ('available', 'reserved', 'sold')");

        DB::statement(<<<'SQL'
            CREATE TABLE parcels (
                id              uuid PRIMARY KEY,
                land_id         uuid NOT NULL REFERENCES lands(id) ON DELETE RESTRICT,
                label           varchar(100) NOT NULL,
                kind            parcel_kind NOT NULL,
                status          parcel_status NOT NULL DEFAULT 'available',
                size_sqft       numeric(14,2) NOT NULL CHECK (size_sqft > 0),
                price           numeric(12,2) NOT NULL CHECK (price >= 0),
                owner_id        uuid REFERENCES users(id) ON DELETE RESTRICT,  
                hold_until      date,
                description     text,
                beds            integer CHECK (beds >= 0),
                baths           numeric(3,1) CHECK (baths >= 0),
                floor_area_sqft numeric(12,2) CHECK (floor_area_sqft > 0),
                year_built      integer CHECK (year_built BETWEEN 1800 AND 2200),
                created_at      timestamptz,
                updated_at      timestamptz,

                CONSTRAINT parcels_label_unique UNIQUE (land_id, label),

                -- Owner and hold date must match the status
                CONSTRAINT parcels_status_fields CHECK (
                    (status = 'available' AND owner_id IS NULL     AND hold_until IS NULL) OR
                    (status = 'reserved'  AND owner_id IS NOT NULL AND hold_until IS NOT NULL) OR
                    (status = 'sold'      AND owner_id IS NOT NULL AND hold_until IS NULL)
                ),

                -- Home details only on houses
                CONSTRAINT parcels_house_fields CHECK (
                    kind = 'house' OR (beds IS NULL AND baths IS NULL AND floor_area_sqft IS NULL AND year_built IS NULL)
                )
            )
        SQL);

        DB::statement('CREATE INDEX parcels_owner_id_index ON parcels (owner_id)');
        DB::statement("CREATE INDEX parcels_hold_until_index ON parcels (hold_until) WHERE status = 'reserved'");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS parcels');
        DB::statement('DROP TYPE IF EXISTS parcel_status');
        DB::statement('DROP TYPE IF EXISTS parcel_kind');
    }
};
