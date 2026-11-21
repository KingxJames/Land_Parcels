<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\LandTracker\Models\Land;
use Modules\LandTracker\Models\Parcel;

// Which company a record belongs to, and whether a user may touch it.
// The platform owner may touch everything; everyone else only their own company.
class Tenancy
{
    // Company id to filter lists by, or null for the platform owner (no filter)
    public static function scope(?User $user): ?string
    {
        return $user?->isPlatformAdmin() ? null : $user?->org_id;
    }

    public static function allows(?User $user, Model $record): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isPlatformAdmin()) {
            return true;
        }
        $org = self::orgOf($record);

        return $org !== null && $org === $user->org_id;
    }

    public static function orgOf(Model $e): ?string
    {
        if ($e instanceof Organization) {
            return $e->id;
        }
        if ($e instanceof User) {
            return $e->org_id;
        }
        if (array_key_exists('org_id', $e->getAttributes())) {
            return $e->org_id;
        }
        if ($e instanceof \Spatie\Permission\Models\Role) {
            return $e->team_id;
        }

        // Things hanging off a parcel: payments, photos, plans, documents...
        $parcelId = $e instanceof Parcel ? $e->id : ($e->getAttributes()['parcel_id'] ?? null);

        // ...or off something that has one
        if (! $parcelId) {
            foreach (['plan', 'payment', 'instalment', 'connection', 'user'] as $rel) {
                if (method_exists($e, $rel) && ($parent = $e->{$rel})) {
                    return self::orgOf($parent);
                }
            }

            return null;
        }

        return Land::whereHas('parcels', fn ($q) => $q->whereKey($parcelId))->value('org_id');
    }
}
