<?php

namespace Modules\LandTracker\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LandTracker\Enums\ParcelStatus;
use Modules\LandTracker\Models\Parcel;
use Modules\LandTracker\Models\PaymentPlan;
use Modules\LandTracker\Services\PaymentAllocator;
use Modules\LandTracker\Services\PaymentSchedule;

class PaymentPlanController extends Controller
{
    // Staff: work out the schedule without saving, for the form
    public function preview(Request $request)
    {
        $terms = $this->validateTerms($request, null);

        return ['instalments' => $this->schedule($terms)];
    }

    public function show(Parcel $parcel)
    {
        return $this->present($parcel->paymentPlan()->firstOrFail());
    }

    // Staff: sign a plan for a reserved or sold parcel, with its owner as the buyer
    public function store(Request $request, Parcel $parcel)
    {
        $terms = $this->validateTerms($request, $parcel);
        $rows = $this->schedule($terms);

        $plan = DB::transaction(function () use ($parcel, $terms, $rows) {
            $p = Parcel::lockForUpdate()->findOrFail($parcel->id);

            if (! in_array($p->status, [ParcelStatus::Reserved, ParcelStatus::Sold], true) || ! $p->owner_id) {
                abort(response()->json(['message' => 'Reserve or sell the parcel to the buyer before adding a payment plan.'], 422));
            }
            if (PaymentPlan::where('parcel_id', $p->id)->exists()) {
                abort(response()->json(['message' => 'This parcel already has a payment plan.'], 409));
            }

            $plan = PaymentPlan::create([...$terms, 'parcel_id' => $p->id, 'owner_id' => $p->owner_id]);
            $plan->instalments()->createMany($rows);

            // Apply money taken before the plan was signed, such as a deposit
            app(PaymentAllocator::class)->allocate($plan);

            return $plan;
        });

        $this->log('created', $plan, $request);

        return response()->json($this->present($plan), 201);
    }

    // Staff: change the terms. Only while nothing has been paid; the schedule is rebuilt.
    public function update(Request $request, Parcel $parcel)
    {
        $plan = $parcel->paymentPlan()->firstOrFail();
        $terms = $this->validateTerms($request, $parcel);
        $rows = $this->schedule($terms);

        DB::transaction(function () use ($plan, $terms, $rows) {
            $locked = PaymentPlan::lockForUpdate()->findOrFail($plan->id);
            if ($locked->hasPayments()) {
                abort(response()->json(['message' => 'Payments have been made on this plan, so its terms can’t be changed.'], 409));
            }
            $locked->update($terms);
            $locked->instalments()->delete();
            $locked->instalments()->createMany($rows);
            app(PaymentAllocator::class)->allocate($locked);
        });

        $this->log('updated', $plan, $request);

        return $this->present($plan->fresh());
    }

    public function destroy(Request $request, Parcel $parcel)
    {
        $plan = $parcel->paymentPlan()->firstOrFail();

        DB::transaction(function () use ($plan) {
            $locked = PaymentPlan::lockForUpdate()->findOrFail($plan->id);
            if ($locked->hasPayments()) {
                abort(response()->json(['message' => 'Payments have been made on this plan, so it can’t be cancelled.'], 409));
            }
            $locked->delete(); // instalments go with it
        });

        $this->log('cancelled', $plan, $request);

        return response()->noContent();
    }

    // Signed-in buyer: their own plan only
    public function mine(Request $request, Parcel $parcel)
    {
        $plan = $parcel->paymentPlan()->where('owner_id', $request->user()->id)->firstOrFail();

        return $this->present($plan);
    }

    private function validateTerms(Request $request, ?Parcel $parcel): array
    {
        $money = ['numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        $data = $request->validate([
            // Defaults to the parcel's price when left out
            'total_price' => [$parcel ? 'nullable' : 'required', ...$money, 'gt:0'],
            'deposit' => ['required', ...$money],
            'monthly_amount' => ['required', ...$money],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'grace_months' => ['sometimes', 'integer', 'between:0,120'],
        ]);

        $data['total_price'] = (string) ($data['total_price'] ?? $parcel->price);
        $data['deposit'] = (string) $data['deposit'];
        $data['monthly_amount'] = (string) $data['monthly_amount'];
        $data['grace_months'] = (int) ($data['grace_months'] ?? 0);

        if ((float) $data['deposit'] > (float) $data['total_price']) {
            abort(response()->json(['message' => 'The deposit can’t be more than the total price.',
                'errors' => ['deposit' => ['The deposit can’t be more than the total price.']]], 422));
        }

        return $data;
    }

    private function schedule(array $t): array
    {
        try {
            return PaymentSchedule::build($t['total_price'], $t['deposit'], $t['monthly_amount'],
                Carbon::parse($t['start_date']), $t['grace_months']);
        } catch (\InvalidArgumentException $e) {
            abort(response()->json(['message' => $e->getMessage(), 'errors' => ['monthly_amount' => [$e->getMessage()]]], 422));
        }
    }

    private function present(PaymentPlan $plan): array
    {
        $plan->load(['instalments', 'owner:id,full_name,email', 'parcel:id,label,land_id']);

        return [...$plan->toArray(), 'summary' => $plan->summary()];
    }

    private function log(string $action, PaymentPlan $plan, Request $request): void
    {
        \Modules\LandTracker\Services\Activity::record('plan_' . $action, $plan, $request->user(),
            $action === 'cancelled' ? null : $plan->only(['total_price', 'deposit', 'monthly_amount', 'start_date', 'grace_months']));

        Log::info('payment_plan.' . $action, ['plan_id' => $plan->id, 'parcel_id' => $plan->parcel_id, 'staff_id' => $request->user()->id]);
    }
}
