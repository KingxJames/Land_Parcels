<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\LandTracker\Enums\PayStatus;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\Payment;
use Modules\LandTracker\Services\PaymentLedger;

class PaymentController extends Controller
{
    private const WITH = ['owner:id,full_name,email', 'recorder:id,full_name', 'confirmer:id,full_name', 'parcel:id,label,land_id', 'receipt'];

    public function __construct(private PaymentLedger $ledger) {}

    // Staff: everything, newest first. ?status=pending is the review queue.
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', Rule::enum(PayStatus::class)]]);

        $org = \App\Support\Tenancy::scope($request->user());

        return Payment::with(self::WITH)
            ->when($org, fn ($q) => $q->whereHas('parcel.land', fn ($l) => $l->where('org_id', $org)))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(\App\Support\Paging::size());
    }

    public function forParcel(Parcel $parcel)
    {
        return $parcel->payments()->with(self::WITH)->get();
    }

    public function show(Payment $payment)
    {
        return $payment->load([...self::WITH, 'allocations.instalment:id,plan_id,seq,label,due_date']);
    }

    // Staff: money handed over or seen at the office
    public function store(Request $request, Parcel $parcel)
    {
        $data = $this->validatePayment($request, Rule::in(\App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($parcel))->officeMethods()));
        $payment = $this->ledger->recordAtOffice($parcel, $request->user(), $data);

        return response()->json($payment->load(self::WITH), 201);
    }

    public function confirm(Request $request, Payment $payment)
    {
        return $this->ledger->confirm($payment, $request->user())->load(self::WITH);
    }

    public function reject(Request $request, Payment $payment)
    {
        return $this->ledger->reject($payment, $request->user())->load(self::WITH);
    }

    // Buyer: their payments for a parcel they own
    public function mine(Request $request, Parcel $parcel)
    {
        abort_unless($parcel->owner_id === $request->user()->id, 404);

        return $parcel->payments()->with('receipt')->where('owner_id', $request->user()->id)->get();
    }

    // Buyer: report a transfer they made, for the office to check
    public function report(Request $request, Parcel $parcel)
    {
        abort_unless($parcel->owner_id === $request->user()->id, 404);

        $data = $this->validatePayment($request, Rule::in(\App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($parcel))->reportableMethods()), true);
        $payment = $this->ledger->reportOnline($parcel, $request->user(), $data);

        return response()->json($payment, 201);
    }

    private function validatePayment(Request $request, $methodRule, bool $online = false): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'method' => ['required', $methodRule],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:' . now()->subYears(5)->toDateString()],
            'reference' => [$online ? 'required' : 'nullable', 'string', 'max:100'],
            // Gateway ids come from the gateway, not from people
            'provider_txn_id' => [$online ? 'prohibited' : 'nullable', 'string', 'max:150'],
        ]);
    }
}
