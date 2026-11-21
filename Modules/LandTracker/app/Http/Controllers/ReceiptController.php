<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Models\Receipt;
use Modules\LandTracker\Services\ChannelNotAvailable;
use Modules\LandTracker\Services\MessageSender;
use Modules\LandTracker\Services\Money;

class ReceiptController extends Controller
{
    // Staff, or the buyer the receipt belongs to
    public function print(Request $request, Receipt $receipt)
    {
        $this->authorizeView($request, $receipt);
        $payment = $receipt->payment->load(['owner:id,full_name', 'parcel.land.organization', 'allocations.instalment']);

        return response()->view('landtracker::receipts.print', [
            'receipt' => $receipt, 'payment' => $payment, 'org' => $payment->parcel->land->organization,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Receipt $receipt)
    {
        $this->authorizeView($request, $receipt);

        return $receipt->load('payment:id,parcel_id,owner_id,amount,method,paid_on');
    }

    // Staff: send to the buyer. Records how and when only if it went out.
    public function send(Request $request, Receipt $receipt, MessageSender $sender)
    {
        $data = $request->validate(['via' => ['required', 'in:email,whatsapp']]);
        $p = $receipt->payment->load(['owner', 'parcel.land.organization']);
        $cur = $p->parcel->land->organization->currency;

        $org = $p->parcel->land->organization;
        $body = $org->settings()->message('receipt', [
            'name' => $p->owner->full_name, 'receipt_no' => $receipt->receipt_no,
            'amount' => Money::format($p->amount, $cur), 'parcel' => $p->parcel->label, 'land' => $p->parcel->land->name,
            'paid_on' => $p->paid_on->format('j F Y'), 'balance' => Money::format($receipt->balance_after, $cur),
            'company' => $org->name,
        ]);

        try {
            $sender->send($p->owner, $data['via'], "Receipt {$receipt->receipt_no}", $body);
        } catch (ChannelNotAvailable $e) {
            throw ValidationException::withMessages(['via' => $e->getMessage()]);
        }

        $receipt->forceFill(['sent_via' => $data['via'], 'sent_at' => now()])->save();

        return $receipt;
    }

    private function authorizeView(Request $request, Receipt $receipt): void
    {
        $user = $request->user();
        abort_unless(($user->isStaff() && \App\Support\Tenancy::allows($user, $receipt->payment)) || $receipt->payment->owner_id === $user->id, 404);
    }
}
