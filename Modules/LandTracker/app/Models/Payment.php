<?php

namespace Modules\LandTracker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\LandTracker\Enums\PaySource;
use Modules\LandTracker\Enums\PayStatus;

// Created and changed only through Modules\LandTracker\Services\PaymentLedger
class Payment extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'source' => PaySource::class,
            'status' => PayStatus::class,
            'paid_on' => 'date:Y-m-d',
            'confirmed_at' => 'datetime',
        ];
    }

    // The method's name as the company calls it, e.g. "E-Kyash"
    public function methodLabel(): string
    {
        return \App\Support\CompanySettings::for(\App\Support\Tenancy::orgOf($this))->label('payment_methods', $this->method);
    }

    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receipt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
