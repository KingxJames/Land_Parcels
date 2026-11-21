<?php

namespace Modules\LandTracker\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $primaryKey = 'org_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['org_id', 'price_per_parcel', 'minimum_monthly', 'monthly_cap', 'billing_cycle', 'status', 'started_at'];

    protected function casts(): array
    {
        return [
            'price_per_parcel' => 'decimal:2',
            'minimum_monthly' => 'decimal:2',
            'monthly_cap' => 'decimal:2',
            'started_at' => 'date:Y-m-d',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
