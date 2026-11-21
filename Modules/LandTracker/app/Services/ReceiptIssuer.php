<?php

namespace Modules\LandTracker\Services;

use Illuminate\Support\Facades\DB;
use Modules\LandTracker\Enums\PayStatus;
use Modules\LandTracker\Models\Payment;
use Modules\LandTracker\Models\PaymentPlan;
use Modules\LandTracker\Models\Receipt;

class ReceiptIssuer
{
    // Called inside the confirming transaction, so a rollback also gives the number back
    public function issue(Payment $payment): Receipt
    {
        if ($existing = Receipt::where('payment_id', $payment->id)->first()) {
            return $existing;
        }

        $org = $payment->parcel->land->organization;

        return Receipt::forceCreate([
            'payment_id' => $payment->id,
            'receipt_no' => $org->receipt_prefix . $this->nextNumber($org->id),
            'balance_after' => $this->balanceAfter($payment),
            'issued_at' => now(),
        ]);
    }

    // Price agreed in the plan (or the parcel's price) minus everything confirmed so far
    private function balanceAfter(Payment $payment): string
    {
        $plan = PaymentPlan::where('parcel_id', $payment->parcel_id)->where('owner_id', $payment->owner_id)->first();
        $price = $plan?->total_price ?? $payment->parcel->price;

        $paid = Payment::where('parcel_id', $payment->parcel_id)->where('owner_id', $payment->owner_id)
            ->where('status', PayStatus::Confirmed->value)->sum('amount');

        return PaymentAllocator::money(max(0, PaymentAllocator::cents($price) - PaymentAllocator::cents($paid)));
    }

    // Gap-free and per company: the company's counter row stays locked until the transaction ends.
    // Prefixes are unique and end in a letter or "-", so numbers never collide between companies.
    private function nextNumber(string $orgId): int
    {
        DB::statement('INSERT INTO receipt_counters (id, next_no) VALUES (?, ?) ON CONFLICT (id) DO NOTHING',
            [$orgId, (int) \App\Support\CompanySettings::for($orgId)->get('receipts.start_number')]);

        $no = (int) DB::table('receipt_counters')->where('id', $orgId)->lockForUpdate()->value('next_no');
        DB::table('receipt_counters')->where('id', $orgId)->update(['next_no' => $no + 1]);

        return $no;
    }
}
