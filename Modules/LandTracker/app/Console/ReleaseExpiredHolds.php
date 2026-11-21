<?php

namespace Modules\LandTracker\Console;

use Illuminate\Console\Command;
use Modules\LandTracker\Services\ParcelSales;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'parcels:release-expired';

    protected $description = 'Put parcels whose reservation ended back on sale';

    public function handle(ParcelSales $sales): int
    {
        $this->info('Released ' . $sales->releaseExpired() . ' parcel(s).');

        return self::SUCCESS;
    }
}
