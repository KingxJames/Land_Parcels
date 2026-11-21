<?php

namespace Modules\LandTracker\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\LandTracker\Enums\ParcelStatus;
use Modules\LandTracker\Models\BuyerProfile;
use Modules\LandTracker\Models\Parcel;

// The only place a parcel's status, owner or hold date changes.
// Each step locks the row so two staff can't reserve the same lot at once.
class ParcelSales
{
    public function reserve(Parcel $parcel, User $buyer, Carbon $holdUntil, User $staff, ?string $price = null): Parcel
    {
        return $this->locked($parcel, function (Parcel $p) use ($buyer, $holdUntil, $staff, $price) {
            $this->expect($p, [ParcelStatus::Available], 'Only an available parcel can be reserved.');
            $this->checkBuyer($buyer, $p);

            $p->status = ParcelStatus::Reserved;
            $p->owner_id = $buyer->id;
            $p->hold_until = $holdUntil->toDateString();
            $oldPrice = $p->price;
            if ($price !== null) {
                $p->price = $price;
            }
            $p->save();

            $this->log('reserved', $p, $staff, $oldPrice);

            return $p;
        });
    }

    public function release(Parcel $parcel, ?User $staff): Parcel
    {
        return $this->locked($parcel, function (Parcel $p) use ($staff) {
            $this->expect($p, [ParcelStatus::Reserved], 'Only a reserved parcel can be released.');

            // A signed plan means the buyer has committed; the daily job skips these too
            if (\Modules\LandTracker\Models\PaymentPlan::where('parcel_id', $p->id)->exists()) {
                $this->fail('status', 'This parcel has a payment plan. Cancel the plan before releasing it.');
            }

            // Money already taken belongs to this buyer; sort it out before freeing the parcel
            if (\Modules\LandTracker\Models\Payment::where('parcel_id', $p->id)->where('status', '<>', 'rejected')->exists()) {
                $this->fail('status', 'This parcel has payments recorded. Refund or reject them before releasing it.');
            }

            $p->status = ParcelStatus::Available;
            $p->owner_id = null;
            $p->hold_until = null;
            $p->save();

            $this->log('released', $p, $staff);

            return $p;
        });
    }

    // Selling needs a buyer whose ID staff have checked (anti-money-laundering)
    public function sell(Parcel $parcel, ?User $buyer, User $staff, ?string $price = null): Parcel
    {
        return $this->locked($parcel, function (Parcel $p) use ($buyer, $staff, $price) {
            $this->expect($p, [ParcelStatus::Available, ParcelStatus::Reserved], 'This parcel is already sold.');

            if ($p->status === ParcelStatus::Reserved && $buyer && $buyer->id !== $p->owner_id) {
                $this->fail('owner_id', 'This parcel is held for someone else. Release it first.');
            }

            $buyer ??= $p->owner;
            if (! $buyer) {
                $this->fail('owner_id', 'Choose who is buying this parcel.');
            }
            $this->checkBuyer($buyer, $p);

            if (! BuyerProfile::whereKey($buyer->id)->whereNotNull('verified_at')->exists()) {
                $this->fail('owner_id', 'The buyer’s ID must be checked by office staff before the sale.');
            }

            $p->status = ParcelStatus::Sold;
            $p->owner_id = $buyer->id;
            $p->hold_until = null;
            $oldPrice = $p->price;
            if ($price !== null) {
                $p->price = $price;
            }
            $p->save();

            $this->log('sold', $p, $staff, $oldPrice);

            return $p;
        });
    }

    // Holds that ended before today go back on sale. Returns how many.
    public function releaseExpired(): int
    {
        $count = 0;
        Parcel::where('status', ParcelStatus::Reserved->value)
            ->where('hold_until', '<', now()->toDateString())
            ->lazyById(100)
            ->each(function (Parcel $p) use (&$count) {
                try {
                    $this->release($p, null);
                    $count++;
                } catch (ValidationException) {
                    // Changed by someone else in the meantime; skip
                }
            });

        return $count;
    }

    private function locked(Parcel $parcel, callable $fn): Parcel
    {
        return DB::transaction(fn () => $fn(Parcel::lockForUpdate()->findOrFail($parcel->id)));
    }

    private function expect(Parcel $p, array $statuses, string $message): void
    {
        if (! in_array($p->status, $statuses, true)) {
            $this->fail('status', $message);
        }
    }

    private function checkBuyer(User $buyer, Parcel $parcel): void
    {
        if ($buyer->org_id !== \App\Support\Tenancy::orgOf($parcel)) {
            $this->fail('owner_id', 'This person isn’t a customer of this company.');
        }
        if ($buyer->status === 'switched_off') {
            $this->fail('owner_id', 'This person’s account is switched off.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function log(string $action, Parcel $p, ?User $staff, ?string $oldPrice = null): void
    {
        if ($oldPrice !== null && $oldPrice !== $p->price) {
            Activity::record('price_changed', $p, $staff, ['old' => ['price' => $oldPrice], 'attributes' => ['price' => $p->price]]);
        }
        Activity::record('parcel_' . $action, $p, $staff, ['owner_id' => $p->owner_id, 'hold_until' => $p->hold_until?->toDateString()]);

        Log::info('parcel.' . $action, [
            'parcel_id' => $p->id, 'owner_id' => $p->owner_id,
            'price' => $p->price, 'staff_id' => $staff?->id,
        ]);
    }
}
