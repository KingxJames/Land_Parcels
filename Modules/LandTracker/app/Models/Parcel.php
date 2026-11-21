<?php

namespace Modules\LandTracker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\LandTracker\Enums\ParcelKind;
use Modules\LandTracker\Enums\ParcelStatus;

class Parcel extends Model
{
    use HasUuids;

    public const HOUSE_FIELDS = ['beds', 'baths', 'floor_area_sqft', 'year_built'];

    // status, owner_id and hold_until change only through reserve(), release() and sell()
    protected $fillable = [
        'land_id', 'label', 'kind', 'size_sqft', 'price', 'description',
        'beds', 'baths', 'floor_area_sqft', 'year_built',
    ];

    protected $attributes = ['status' => 'available'];

    protected function casts(): array
    {
        return [
            'kind' => ParcelKind::class,
            'status' => ParcelStatus::class,
            'size_sqft' => 'decimal:2',
            'price' => 'decimal:2',
            'baths' => 'decimal:1',
            'floor_area_sqft' => 'decimal:2',
            'beds' => 'integer',
            'year_built' => 'integer',
            'hold_until' => 'date:Y-m-d',
        ];
    }

    // Clear home details when a parcel is switched to plain land
    protected static function booted(): void
    {
        // The database removes photo rows; remove their files once that has committed
        static::deleting(function (Parcel $parcel) {
            $paths = $parcel->photos()->pluck('file_path')->all();
            DB::afterCommit(fn () => Storage::disk(\App\Support\Disks::uploads())->delete($paths));
        });

        static::saving(function (Parcel $parcel) {
            if ($parcel->kind === ParcelKind::Land) {
                foreach (self::HOUSE_FIELDS as $f) {
                    $parcel->{$f} = null;
                }
            }
        });
    }

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function outline(): HasOne
    {
        return $this->hasOne(ParcelOutline::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ParcelPhoto::class)->orderBy('sort_order')->orderBy('created_at');
    }

    public function paymentPlan(): HasOne
    {
        return $this->hasOne(PaymentPlan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByDesc('paid_on')->orderByDesc('created_at');
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class)->orderByDesc('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->orderByDesc('created_at');
    }

    public function scopeOrdered(Builder $q): Builder
    {
        // Natural order so "Lot 2" comes before "Lot 10"
        return $q->orderByRaw("regexp_replace(label, '\\d+', '', 'g'), nullif(regexp_replace(label, '\\D', '', 'g'), '')::numeric NULLS FIRST, label");
    }

    // What the public website may see: no owner, no hold date
    public function toPublicArray(): array
    {
        return [
            ...$this->only(['id', 'land_id', 'label', 'kind', 'status', 'size_sqft', 'price', 'description', ...self::HOUSE_FIELDS]),
            'outline' => $this->relationLoaded('outline') ? $this->outline?->points : null,
            'photos' => $this->relationLoaded('photos') ? $this->photos->map->only(['id', 'url', 'sort_order'])->values() : [],
        ];
    }
}
