<?php

namespace Modules\LandTracker\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Instalment extends Model
{
    use HasUuids;

    public $timestamps = false; // table has none, as designed

    // amount_paid is not fillable: only payment allocations should change it
    protected $fillable = ['plan_id', 'seq', 'label', 'due_date', 'amount'];

    protected $appends = ['status'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'due_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function reminders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Reminder::class)->orderByDesc('sent_at');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'plan_id');
    }

    // paid, part_paid, overdue or upcoming
    public function getStatusAttribute(): string
    {
        $owed = round((float) $this->amount - (float) $this->amount_paid, 2);
        if ($owed <= 0) {
            return 'paid';
        }
        if ($this->due_date && $this->due_date->isBefore(today())) {
            return 'overdue';
        }

        return (float) $this->amount_paid > 0 ? 'part_paid' : 'upcoming';
    }
}
