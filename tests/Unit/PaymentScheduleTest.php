<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Modules\LandTracker\Services\PaymentSchedule;
use PHPUnit\Framework\TestCase;

class PaymentScheduleTest extends TestCase
{
    public function test_deposit_then_months_with_smaller_last_payment(): void
    {
        $rows = PaymentSchedule::build('10000.00', '1000.00', '2500.00', Carbon::parse('2027-01-15'), 0);

        $this->assertSame(['Deposit', 'Month 1', 'Month 2', 'Month 3', 'Month 4'], array_column($rows, 'label'));
        $this->assertSame([0, 1, 2, 3, 4], array_column($rows, 'seq'));
        $this->assertSame(['1000.00', '2500.00', '2500.00', '2500.00', '1500.00'], array_column($rows, 'amount'));
        $this->assertSame(['2027-01-15', '2027-02-15', '2027-03-15', '2027-04-15', '2027-05-15'], array_column($rows, 'due_date'));
    }

    public function test_grace_months_push_back_the_first_monthly_payment(): void
    {
        $rows = PaymentSchedule::build('3000', '1000', '1000', Carbon::parse('2027-01-15'), 2);
        $this->assertSame(['2027-01-15', '2027-04-15', '2027-05-15'], array_column($rows, 'due_date'));
    }

    public function test_pay_in_full_and_no_deposit(): void
    {
        $rows = PaymentSchedule::build('5000', '0', '0', Carbon::parse('2027-01-15'), 0);
        $this->assertSame([['seq' => 1, 'label' => 'Balance', 'due_date' => '2027-02-15', 'amount' => '5000.00']], $rows);

        $this->assertCount(1, PaymentSchedule::build('5000', '5000', '0', Carbon::parse('2027-01-15'), 0)); // deposit only
    }

    public function test_month_end_start_stays_at_month_end(): void
    {
        $rows = PaymentSchedule::build('400', '100', '100', Carbon::parse('2027-01-31'), 0);
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30'], array_column($rows, 'due_date'));
    }

    public function test_cents_add_up_exactly(): void
    {
        $rows = PaymentSchedule::build('1000.01', '0.10', '333.33', Carbon::parse('2027-01-01'), 0);
        $sum = array_sum(array_map(fn ($r) => (int) round($r['amount'] * 100), $rows));
        $this->assertSame(100001, $sum);
        $this->assertSame('333.25', end($rows)['amount']); // 999.91 left after deposit, minus 2 x 333.33
    }

    public function test_too_many_months_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentSchedule::build('1000000', '0', '1', Carbon::parse('2027-01-01'), 0);
    }
}
