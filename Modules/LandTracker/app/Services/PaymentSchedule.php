<?php

namespace Modules\LandTracker\Services;

use Illuminate\Support\Carbon;

// Works out every payment due under a plan. All money is handled in whole cents.
//
//   seq 0  Deposit   due on start_date (left out when the deposit is 0)
//   Pay in full (monthly_amount = 0): one "Balance" payment
//   Otherwise "Month 1", "Month 2"... each monthly_amount, the last one smaller if needed
//
// The first payment after the deposit is due (grace_months + 1) months after start_date,
// so grace_months = 0 means one month after the deposit.
class PaymentSchedule
{
    public const MAX_INSTALMENTS = 600; // 50 years of monthly payments

    /** @return array<int, array{seq:int,label:string,due_date:string,amount:string}> */
    public static function build(string $totalPrice, string $deposit, string $monthly, Carbon $start, int $graceMonths): array
    {
        $total = self::cents($totalPrice);
        $dep = self::cents($deposit);
        $month = self::cents($monthly);
        $rows = [];

        if ($dep > 0) {
            $rows[] = self::row(0, 'Deposit', $start, $dep);
        }

        $left = $total - $dep;
        if ($left <= 0) {
            return $rows;
        }

        $firstDue = $start->copy()->addMonthsNoOverflow($graceMonths + 1);

        if ($month === 0) {
            $rows[] = self::row(1, 'Balance', $firstDue, $left);

            return $rows;
        }

        $count = intdiv($left + $month - 1, $month);
        if ($count > self::MAX_INSTALMENTS) {
            throw new \InvalidArgumentException('That monthly amount would take more than ' . self::MAX_INSTALMENTS . ' months.');
        }

        for ($n = 1; $n <= $count; $n++) {
            $amount = min($month, $left);
            // Count from the start date each time so a plan starting on the 31st stays at month end
            $rows[] = self::row($n, "Month $n", $start->copy()->addMonthsNoOverflow($graceMonths + $n), $amount);
            $left -= $amount;
        }

        return $rows;
    }

    private static function row(int $seq, string $label, Carbon $due, int $cents): array
    {
        return ['seq' => $seq, 'label' => $label, 'due_date' => $due->toDateString(), 'amount' => number_format($cents / 100, 2, '.', '')];
    }

    private static function cents(string $value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
