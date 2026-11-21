<?php

namespace Modules\LandTracker\Console;

use Illuminate\Console\Command;
use Modules\LandTracker\Services\ReminderService;

class SendPaymentReminders extends Command
{
    protected $signature = 'reminders:send-due';

    protected $description = 'Send reminders for instalments due soon or overdue';

    public function handle(ReminderService $reminders): int
    {
        $r = $reminders->sendDue();
        $this->info("Sent {$r['sent']} reminder(s), {$r['failed']} failed.");

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
