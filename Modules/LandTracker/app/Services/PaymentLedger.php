<?php

namespace Modules\LandTracker\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Enums\PaySource;
use Modules\LandTracker\Enums\PayStatus;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Payment;

// The only place payments are created or change status.
//
//   Office (staff record it)      -> confirmed straight away; the staff member saw the money
//   Online transfer (buyer reports)-> pending until staff confirm or reject it
//   Online card (gateway)         -> confirmed straight away, no staff member
class PaymentLedger
{
    /** @param array{amount:string, method:string, paid_on:string, reference?:?string, provider_txn_id?:?string} $data */
    public function recordAtOffice(Parcel $parcel, User $staff, array $data): Payment
    {
        if (! in_array($data['method'], \App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($parcel))->officeMethods(), true)) {
            $this->fail('method', 'This company doesn’t take that payment method at the office.');
        }

        return $this->create($parcel, null, $data, [
            'source' => PaySource::Office,
            'status' => PayStatus::Confirmed,
            'recorded_by' => $staff->id,
            'confirmed_by' => $staff->id,
            'confirmed_at' => now(),
        ], $staff);
    }

    // A buyer says they sent a transfer; the office checks it arrived
    public function reportOnline(Parcel $parcel, User $buyer, array $data): Payment
    {
        if (! in_array($data['method'], \App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($parcel))->reportableMethods(), true)) {
            $this->fail('method', 'That payment method can’t be reported online.');
        }
        if (empty($data['reference'])) {
            $this->fail('reference', 'Add the transaction number from your bank or app.');
        }

        return $this->create($parcel, $buyer, $data, ['source' => PaySource::Online, 'status' => PayStatus::Pending], $buyer);
    }

    // From a verified provider notice (E-Kyash, DigiWallet or card gateway): arrives confirmed
    public function recordGatewayPayment(Parcel $parcel, User $buyer, string $method, string $amount, string $providerTxnId, string $paidOn): Payment
    {
        $data = ['amount' => $amount, 'method' => $method, 'paid_on' => $paidOn, 'provider_txn_id' => $providerTxnId];

        return $this->create($parcel, $buyer, $data, [
            'source' => PaySource::Online, 'status' => PayStatus::Confirmed, 'confirmed_at' => now(),
        ], null);
    }

    public function recordCardPayment(Parcel $parcel, User $buyer, string $amount, string $providerTxnId, string $paidOn): Payment
    {
        return $this->recordGatewayPayment($parcel, $buyer, \App\Support\CompanySettings::GATEWAY_ONLY, $amount, $providerTxnId, $paidOn);
    }

    public function confirm(Payment $payment, User $staff): Payment
    {
        return $this->decide($payment, $staff, PayStatus::Confirmed);
    }

    public function reject(Payment $payment, User $staff): Payment
    {
        return $this->decide($payment, $staff, PayStatus::Rejected);
    }

    private function create(Parcel $parcel, ?User $payer, array $data, array $state, ?User $actor): Payment
    {
        return DB::transaction(function () use ($parcel, $payer, $data, $state, $actor) {
            $p = Parcel::lockForUpdate()->findOrFail($parcel->id);

            // Money is only taken for a parcel someone holds or has bought, and from that person
            if (! $p->owner_id) {
                $this->fail('parcel', 'Reserve or sell this parcel before taking payments for it.');
            }
            if ($payer && $payer->id !== $p->owner_id) {
                $this->fail('parcel', 'Only the parcel’s owner can pay for it.');
            }

            $reference = isset($data['reference']) ? trim($data['reference']) : null;
            if ($reference !== null && $reference !== '' && Payment::where('method', $data['method'])
                ->whereRaw('lower(reference) = lower(?)', [$reference])
                ->where('status', '<>', PayStatus::Rejected->value)->exists()) {
                $this->fail('reference', 'A payment with this transaction number is already recorded.');
            }

            $payment = new Payment;
            $payment->forceFill([
                'parcel_id' => $p->id,
                'owner_id' => $p->owner_id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'paid_on' => $data['paid_on'],
                'reference' => $reference ?: null,
                'provider_txn_id' => $data['provider_txn_id'] ?? null,
                ...$state,
            ])->save();

            if ($payment->status === PayStatus::Confirmed) {
                $this->applyConfirmed($payment);
            }

            $this->log('recorded', $payment, $actor);

            return $payment;
        });
    }

    private function decide(Payment $payment, User $staff, PayStatus $to): Payment
    {
        return DB::transaction(function () use ($payment, $staff, $to) {
            $p = Payment::lockForUpdate()->findOrFail($payment->id);

            if ($p->status !== PayStatus::Pending) {
                $this->fail('status', 'This payment has already been ' . $p->status->value . '.');
            }
            if ($p->owner_id === $staff->id) {
                $this->fail('status', 'You can’t confirm or reject your own payment.');
            }

            $p->forceFill(['status' => $to, 'confirmed_by' => $staff->id, 'confirmed_at' => now()])->save();

            if ($to === PayStatus::Confirmed) {
                $this->applyConfirmed($p);
            }

            $this->log($to->value, $p, $staff);

            return $p;
        });
    }

    // Runs inside the transaction whenever a payment becomes confirmed
    private function applyConfirmed(Payment $payment): void
    {
        app(PaymentAllocator::class)->allocateForParcel($payment->parcel_id);
        app(ReceiptIssuer::class)->issue($payment);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function log(string $action, Payment $p, ?User $actor): void
    {
        Activity::record('payment_' . $action, $p, $actor, ['amount' => $p->amount, 'method' => $p->method, 'status' => $p->status->value]);

        Log::info('payment.' . $action, [
            'payment_id' => $p->id, 'parcel_id' => $p->parcel_id, 'amount' => $p->amount,
            'method' => $p->method, 'actor_id' => $actor?->id,
        ]);
    }
}
