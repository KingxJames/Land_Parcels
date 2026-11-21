<?php

namespace App\Support;

// Where uploaded files are kept. Set in .env; see config/filesystems.php.
class Disks
{
    // Site plans, parcel photos, documents and company logos
    public static function uploads(): string
    {
        return config('filesystems.uploads', 'local');
    }

    // Buyer ID scans for anti-money-laundering checks
    public static function idScans(): string
    {
        return config('filesystems.id_scans', 'kyc');
    }
}
