<?php

namespace Modules\LandTracker\Console;

use Illuminate\Console\Command;
use Modules\LandTracker\Services\Billing;

class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Make this period’s company invoices and mark late ones overdue';

    public function handle(Billing $billing): int
    {
        $made = $billing->generate();
        $late = $billing->markOverdue();
        $this->info("Made {$made} invoice(s), marked {$late} overdue.");

        return self::SUCCESS;
    }
}
