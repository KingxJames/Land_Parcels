<?php

namespace Modules\LandTracker\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Payment;
use Modules\LandTracker\Models\PaymentConnection;
use Modules\LandTracker\Models\PaymentNotice;

// Turns a provider's message into a payment.
//
// Expected message (adapt per provider once their documentation is in hand):
//   header  X-Signature: hex HMAC-SHA256 of the raw body, keyed with the callback secret
//   body    {"invoice_id": "...", "status": "approved", "amount": "500.00",
//            "transaction_id": "...", "paid_on": "2026-11-18",
//            "metadata": {"parcel_id": "<uuid>", "owner_id": 12}}
//
// metadata is what our checkout sends to the provider and the provider echoes back.
class PaymentNoticeHandler
{
    public const APPROVED = ['approved', 'paid', 'success', 'completed'];

    public function __construct(private PaymentLedger $ledger) {}

    public function verify(PaymentConnection $c, string $rawBody, ?string $signature): bool
    {
        $secret = $c->callback_secret_encrypted;

        return $secret && $signature && hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }

    public function handle(PaymentConnection $c, array $payload): PaymentNotice
    {
        $notice = PaymentNotice::forceCreate([
            'connection_id' => $c->id,
            'external_invoice_id' => (string) ($payload['invoice_id'] ?? ''),
            'status' => strtolower((string) ($payload['status'] ?? 'unknown')),
            'payload' => $payload,
            'received_at' => now(),
        ]);

        if (! in_array($notice->status, self::APPROVED, true)) {
            return $notice; // declined, pending and the like are kept for the record only
        }
        if ($c->mode !== 'live') {
            Log::info('payment_notice.test_mode', ['notice_id' => $notice->id]);

            return $notice; // test connections never create real payments
        }

        try {
            $payment = $this->paymentFor($c, $payload);
            $notice->forceFill(['payment_id' => $payment->id])->save();
        } catch (\Throwable $e) {
            // Kept with no payment so the office can look into it
            Log::warning('payment_notice.unmatched', ['notice_id' => $notice->id, 'error' => $e->getMessage()]);
        }

        return $notice;
    }

    private function paymentFor(PaymentConnection $c, array $p): Payment
    {
        $txn = (string) ($p['transaction_id'] ?? '');
        if ($txn === '') {
            throw new \RuntimeException('No transaction id');
        }

        // The provider resends notices; the same transaction is only ever one payment
        $existing = Payment::where('method', $c->payMethod())->where('provider_txn_id', $txn)->first();
        if ($existing) {
            return $existing;
        }

        $parcel = Parcel::with('land')->findOrFail($p['metadata']['parcel_id'] ?? null);
        if ($parcel->land->org_id !== $c->org_id) {
            throw new \RuntimeException('Parcel belongs to another company');
        }
        $buyer = User::findOrFail($p['metadata']['owner_id'] ?? null);

        $amount = $p['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new \RuntimeException('Bad amount');
        }

        $paidOn = isset($p['paid_on']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['paid_on']) ? $p['paid_on'] : now()->toDateString();

        try {
            return DB::transaction(fn () => $this->ledger->recordGatewayPayment(
                $parcel, $buyer, $c->payMethod(), number_format((float) $amount, 2, '.', ''), $txn, $paidOn));
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The same notice arrived twice at once and the other one won
            return Payment::where('method', $c->payMethod())->where('provider_txn_id', $txn)->firstOrFail();
        }
    }
}
