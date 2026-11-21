<?php

namespace Modules\LandTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Written only by Modules\LandTracker\Services\PaymentAllocator
class PaymentAllocation extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null; // two-column key: payment_id + instalment_id

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(Instalment::class);
    }
}
