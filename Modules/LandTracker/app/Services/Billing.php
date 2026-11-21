<?php

namespace Modules\LandTracker\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\LandTracker\Models\BillingInvoice;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Subscription;

// Your bill to each company.
//
//   billable parcels = for sale + reserved + sold but still being paid off
//   monthly amount   = parcels x price, at least the minimum, at most the cap
//   monthly cycle    = one invoice on the 1st of each month
//   yearly cycle     = one invoice a year, in the month the subscription started, for 12 months
//   trial, cancelled = no invoices
//   unpaid platform.billing_days_to_pay days after the period starts -> overdue, and the subscription goes past_due
class Billing
{
    public function billableParcels(string $orgId): int
    {
        return Parcel::whereHas('land', fn ($q) => $q->where('org_id', $orgId))
            ->where(fn ($q) => $q->whereIn('status', ['available', 'reserved'])
                ->orWhere(fn ($q) => $q->where('status', 'sold')
                    ->whereHas('paymentPlan.instalments', fn ($i) => $i->whereColumn('amount_paid', '<', 'amount'))))
            ->count();
    }

    public function monthlyAmount(Subscription $s, int $parcels): string
    {
        $cents = $parcels * PaymentAllocator::cents($s->price_per_parcel);
        $cents = max($cents, PaymentAllocator::cents($s->minimum_monthly));
        if ($s->monthly_cap !== null) {
            $cents = min($cents, PaymentAllocator::cents($s->monthly_cap));
        }

        return PaymentAllocator::money($cents);
    }

    // Safe to run every day: a period is only ever billed once
    public function generate(?Carbon $today = null): int
    {
        $today ??= today();
        $period = $today->copy()->startOfMonth();
        $made = 0;

        Subscription::whereIn('status', ['active', 'past_due'])
            ->where('started_at', '<=', $today->toDateString())
            ->each(function (Subscription $s) use ($period, &$made) {
                $yearly = $s->billing_cycle === 'yearly';
                if ($yearly && $s->started_at->month !== $period->month) {
                    return;
                }
                if (BillingInvoice::where('org_id', $s->org_id)->where('period_start', $period->toDateString())->exists()) {
                    return;
                }

                $count = $this->billableParcels($s->org_id);
                $amount = $this->monthlyAmount($s, $count);
                if ($yearly) {
                    $amount = PaymentAllocator::money(PaymentAllocator::cents($amount) * 12);
                }

                BillingInvoice::forceCreate([
                    'org_id' => $s->org_id, 'period_start' => $period->toDateString(),
                    'billable_parcels' => $count, 'amount' => $amount, 'status' => 'open',
                ]);
                $made++;
            });

        return $made;
    }

    public function markOverdue(?Carbon $today = null): int
    {
        $cutoff = ($today ?? today())->copy()->subDays((int) config('platform.billing_days_to_pay', 14))->toDateString();

        return DB::transaction(function () use ($cutoff) {
            $orgs = BillingInvoice::where('status', 'open')->where('period_start', '<', $cutoff)->pluck('org_id')->unique();
            $n = BillingInvoice::where('status', 'open')->where('period_start', '<', $cutoff)->update(['status' => 'overdue', 'updated_at' => now()]);
            Subscription::whereIn('org_id', $orgs)->where('status', 'active')->update(['status' => 'past_due', 'updated_at' => now()]);

            return $n;
        });
    }

    public function markPaid(BillingInvoice $invoice): BillingInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $inv = BillingInvoice::lockForUpdate()->findOrFail($invoice->id);
            if ($inv->status === 'paid') {
                return $inv;
            }
            $inv->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

            // Nothing else overdue: back to active
            if (! BillingInvoice::where('org_id', $inv->org_id)->where('status', 'overdue')->exists()) {
                Subscription::whereKey($inv->org_id)->where('status', 'past_due')->update(['status' => 'active', 'updated_at' => now()]);
            }

            return $inv;
        });
    }
}
