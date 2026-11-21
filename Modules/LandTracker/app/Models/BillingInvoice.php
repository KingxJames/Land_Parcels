<?php

namespace Modules\LandTracker\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Written only by Modules\LandTracker\Services\Billing
class BillingInvoice extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'amount' => 'decimal:2', 'billable_parcels' => 'integer', 'paid_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
