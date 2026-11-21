<?php

namespace Modules\LandTracker\Services;

use Illuminate\Support\Facades\DB;
use Modules\LandTracker\Enums\PayStatus;
use Modules\LandTracker\Models\Instalment;
use Modules\LandTracker\Models\Payment;
use Modules\LandTracker\Models\PaymentAllocation;
use Modules\LandTracker\Models\PaymentPlan;

// Spreads confirmed money over a plan's instalments, oldest first, and keeps
// instalments.amount_paid equal to the sum of their allocations.
//
// Safe to run any time: it only uses money not yet allocated, so running it twice
// changes nothing. Money left over (no plan yet, or more than is owed) stays
// unallocated as credit and is applied the next time this runs.
class PaymentAllocator
{
    public function allocateForParcel(string $parcelId): void
    {
        $plan = PaymentPlan::where('parcel_id', $parcelId)->first();
        if ($plan) {
            $this->allocate($plan);
        }
    }

    public function allocate(PaymentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            // One allocation run per plan at a time
            $plan = PaymentPlan::lockForUpdate()->findOrFail($plan->id);

            $payments = $this->unallocated($plan->parcel_id, $plan->owner_id);
            if (! $payments) {
                return;
            }

            $instalments = Instalment::where('plan_id', $plan->id)->orderBy('seq')->lockForUpdate()->get();

            foreach ($instalments as $inst) {
                $owed = self::cents($inst->amount) - self::cents($inst->amount_paid);

                while ($owed > 0 && $payments) {
                    $take = min($owed, $payments[0]['left']);

                    $this->addAllocation($payments[0]['id'], $inst->id, $take);
                    $owed -= $take;
                    $payments[0]['left'] -= $take;
                    if ($payments[0]['left'] === 0) {
                        array_shift($payments);
                    }
                }

                $paid = self::cents($inst->amount) - $owed;
                if ($paid !== self::cents($inst->amount_paid)) {
                    Instalment::whereKey($inst->id)->update(['amount_paid' => self::money($paid)]);
                }

                if (! $payments) {
                    break;
                }
            }
        });
    }

    // Confirmed money for this parcel and buyer not yet used, oldest payment first
    private function unallocated(string $parcelId, string $ownerId): array
    {
        $rows = Payment::query()
            ->where('parcel_id', $parcelId)
            ->where('owner_id', $ownerId)
            ->where('status', PayStatus::Confirmed->value)
            ->orderBy('paid_on')->orderBy('created_at')->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'amount']);

        $used = PaymentAllocation::whereIn('payment_id', $rows->pluck('id'))
            ->groupBy('payment_id')->selectRaw('payment_id, sum(amount) as total')
            ->pluck('total', 'payment_id');

        return $rows->map(fn ($p) => ['id' => $p->id, 'left' => self::cents($p->amount) - self::cents($used[$p->id] ?? 0)])
            ->filter(fn ($p) => $p['left'] > 0)->values()->all();
    }

    // A payment may top up an instalment it already part-paid
    private function addAllocation(string $paymentId, string $instalmentId, int $cents): void
    {
        DB::statement(
            'INSERT INTO payment_allocations (payment_id, instalment_id, amount) VALUES (?, ?, ?)
             ON CONFLICT (payment_id, instalment_id) DO UPDATE SET amount = payment_allocations.amount + EXCLUDED.amount',
            [$paymentId, $instalmentId, self::money($cents)],
        );
    }

    // Confirmed money not yet allocated to any instalment
    public static function creditFor(string $parcelId, string $ownerId): string
    {
        $paid = (string) Payment::where('parcel_id', $parcelId)->where('owner_id', $ownerId)
            ->where('status', PayStatus::Confirmed->value)->sum('amount');
        $used = (string) PaymentAllocation::whereIn('payment_id', Payment::where('parcel_id', $parcelId)->where('owner_id', $ownerId)->select('id'))
            ->sum('amount');

        return self::money(self::cents($paid) - self::cents($used));
    }

    public static function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
