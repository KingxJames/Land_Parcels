<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\LandTracker\Models\BillingInvoice;
use App\Models\Organization;
use Modules\LandTracker\Models\Subscription;
use Modules\LandTracker\Services\Activity;
use Modules\LandTracker\Services\Billing;

// super_admin only: what companies pay you
class BillingController extends Controller
{
    public function subscriptions()
    {
        return Subscription::with('organization:id,name')->orderBy('started_at')->get();
    }

    // Set or change a company's terms
    public function saveSubscription(Request $request, Organization $org)
    {
        $money = ['numeric', 'min:0', 'max:999999.99', 'decimal:0,2'];
        $data = $request->validate([
            'price_per_parcel' => ['required', ...$money],
            'minimum_monthly' => ['required', ...$money],
            'monthly_cap' => ['nullable', ...$money, 'gte:minimum_monthly'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
            'status' => ['required', 'in:trial,active,past_due,cancelled'],
            'started_at' => ['required', 'date_format:Y-m-d'],
        ]);

        $sub = Subscription::find($org->id);
        $before = $sub?->getAttributes() ?? [];
        $sub = Subscription::updateOrCreate(['org_id' => $org->id], $data);

        Activity::record($sub->wasRecentlyCreated ? 'subscription_started' : 'subscription_changed', $sub, $request->user(),
            $sub->wasRecentlyCreated ? $data : Activity::changes($sub, $before), $org->id);

        return $sub;
    }

    // What this month's bill would be right now
    public function preview(Organization $org, Billing $billing)
    {
        $sub = Subscription::findOrFail($org->id);
        $count = $billing->billableParcels($org->id);

        return ['billable_parcels' => $count, 'monthly_amount' => $billing->monthlyAmount($sub, $count)];
    }

    public function invoices(Request $request)
    {
        $request->validate(['org_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:open,paid,overdue']]);

        return BillingInvoice::with('organization:id,name')
            ->when($request->org_id, fn ($q, $v) => $q->where('org_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('period_start')->paginate(\App\Support\Paging::size());
    }

    public function markPaid(Request $request, BillingInvoice $invoice, Billing $billing)
    {
        $inv = $billing->markPaid($invoice);
        Activity::record('billing_invoice_paid', $inv, $request->user(), ['amount' => $inv->amount], $inv->org_id);

        return $inv;
    }
}
