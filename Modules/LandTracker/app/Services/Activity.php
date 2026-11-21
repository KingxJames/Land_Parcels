<?php

namespace Modules\LandTracker\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// One entry in the activity log (spatie/laravel-activitylog's table, plus org_id).
//
//   log_name     what kind of thing: parcels, payments, lands...
//   description  readable: "Changed price of Lot 3"
//   event        created, updated or deleted
//   properties   {"action": "price_changed", "old": {...}, "attributes": {...}} or other details
class Activity
{
    // Wording for the description; anything not listed falls back to the action's words
    private const WORDS = [
        'price_changed' => 'Changed price of',
        'parcel_created' => 'Added parcel', 'parcel_updated' => 'Edited', 'parcel_deleted' => 'Deleted parcel',
        'parcel_reserved' => 'Reserved', 'parcel_released' => 'Released', 'parcel_sold' => 'Sold',
        'outline_edited' => 'Edited the outline of', 'outline_removed' => 'Removed the outline of',
        'land_created' => 'Added land', 'land_updated' => 'Edited land', 'land_deleted' => 'Deleted land',
        'payment_recorded' => 'Recorded', 'payment_confirmed' => 'Confirmed', 'payment_rejected' => 'Rejected',
        'plan_created' => 'Set up', 'plan_updated' => 'Changed', 'plan_cancelled' => 'Cancelled',
        'document_uploaded' => 'Uploaded', 'document_deleted' => 'Deleted',
        'enquiry_reserved' => 'Reserved a parcel from the enquiry by',
        'connection_added' => 'Connected', 'connection_updated' => 'Changed the', 'connection_removed' => 'Disconnected',
        'subscription_started' => 'Started the', 'subscription_changed' => 'Changed the',
        'billing_invoice_paid' => 'Marked paid:',
        'organization_created' => 'Created company', 'organization_updated' => 'Edited company',
        'organization_status_changed' => 'Changed the status of', 'logo_changed' => 'Changed the logo of',
        'settings_changed' => 'Changed the settings of',
        'role_created' => 'Added role', 'role_updated' => 'Changed role', 'role_deleted' => 'Deleted role',
        'person_invited' => 'Invited', 'person_updated' => 'Changed', 'person_role_changed' => 'Changed the role of',
    ];

    // $label replaces the thing's usual name in the description, e.g. a role's name when the entry is filed under its company
    public static function record(string $action, Model $entity, ?User $user, ?array $details = null, ?string $orgId = null, ?string $label = null): void
    {
        $logger = activity(self::logName($entity))
            ->performedOn($entity)
            ->event(self::eventOf($action))
            ->withProperties(['action' => $action, ...($details ?? [])])
            ->tap(fn ($a) => $a->org_id = $orgId ?? self::orgOf($entity));

        // Say plainly who did it; automatic changes (webhooks, daily jobs) have nobody
        $user ? $logger->causedBy($user) : $logger->causedByAnonymous();

        $logger->log(trim((self::WORDS[$action] ?? Str::ucfirst(str_replace('_', ' ', $action))) . ' ' . ($label ?? self::labelOf($entity))));
    }

    // What the last save changed, in the package's shape:
    // {"old": {"price": "22000.00"}, "attributes": {"price": "24000.00"}}
    public static function changes(Model $m, array $original): array
    {
        $old = $new = [];
        foreach (array_keys($m->getChanges()) as $k) {
            if (in_array($k, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $from = $original[$k] ?? null;
            $to = $m->getAttribute($k); // as the model formats it, e.g. "24000.00"
            $old[$k] = $from instanceof \BackedEnum ? $from->value : $from;
            $new[$k] = $to instanceof \BackedEnum ? $to->value : $to;
        }

        return $new ? ['old' => $old, 'attributes' => $new] : [];
    }

    // Pull one field's change out of changes(), e.g. the price
    public static function only(array $changes, string $field): ?array
    {
        if (! array_key_exists($field, $changes['attributes'] ?? [])) {
            return null;
        }

        return ['old' => [$field => $changes['old'][$field]], 'attributes' => [$field => $changes['attributes'][$field]]];
    }

    public static function without(array $changes, string $field): array
    {
        unset($changes['old'][$field], $changes['attributes'][$field]);

        return empty($changes['attributes']) ? [] : $changes;
    }

    private static function orgOf(Model $e): ?string
    {
        return \App\Support\Tenancy::orgOf($e);
    }

    private static function logName(Model $e): string
    {
        return Str::plural(Str::snake(class_basename($e)));
    }

    private static function eventOf(string $action): string
    {
        return match (true) {
            (bool) preg_match('/_(created|added|uploaded|started|recorded)$/', $action) => 'created',
            (bool) preg_match('/_(deleted|removed|cancelled)$/', $action) => 'deleted',
            default => 'updated',
        };
    }

    private static function labelOf(Model $e): string
    {
        return match (class_basename($e)) {
            'Parcel' => (string) $e->label,
            'Land', 'Organization' => (string) $e->name,
            'Payment' => 'payment of ' . Money::format($e->amount, $e->parcel?->land?->organization?->currency) . ' for ' . $e->parcel?->label,
            'PaymentPlan' => 'payment plan for ' . $e->parcel?->label,
            'Document' => (string) $e->file_name,
            'Enquiry' => (string) $e->name,
            'PaymentConnection' => str_replace('_', ' ', (string) $e->provider) . ' connection',
            'Subscription' => 'subscription',
            'User' => (string) $e->full_name,
            'BillingInvoice' => 'invoice for ' . $e->period_start?->format('F Y'),
            default => Str::headline(class_basename($e)),
        };
    }
}
