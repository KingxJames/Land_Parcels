<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Each company that subscribes (a developer or land seller). Every other record belongs to one.
// Lives in Auth because users belong to a company.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TYPE IF EXISTS org_status'); // migrate:fresh leaves types behind
        DB::statement("CREATE TYPE org_status AS ENUM ('trial', 'active', 'suspended')");

        DB::statement(<<<'SQL'
            CREATE TABLE organizations (
                id                   uuid PRIMARY KEY,
                name                 varchar(150) NOT NULL,
                slug                 varchar(60)  NOT NULL UNIQUE,           -- web address name, e.g. cayo-ridge
                currency             char(3)      NOT NULL DEFAULT 'BZD',
                theme                varchar(40)  NOT NULL DEFAULT 'default',
                phone                varchar(40),
                whatsapp             varchar(40),
                email                varchar(255),
                address              text,
                created_at           timestamptz,
                updated_at           timestamptz,
                status               org_status   NOT NULL DEFAULT 'trial',
                logo_path            varchar(255),
                -- Unique and ending in a letter or "-", so receipt numbers can never collide between companies
                receipt_prefix       varchar(8)   NOT NULL UNIQUE,
                reminder_days_before integer      NOT NULL DEFAULT 3,

                CONSTRAINT organizations_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                CONSTRAINT organizations_currency_format CHECK (currency ~ '^[A-Z]{3}$'),
                CONSTRAINT organizations_receipt_prefix_format CHECK (receipt_prefix ~ '^[A-Z0-9]{0,7}[A-Z-]$'),
                CONSTRAINT organizations_reminder_days CHECK (reminder_days_before BETWEEN 0 AND 60)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS organizations');
        DB::statement('DROP TYPE IF EXISTS org_status');
    }
};
