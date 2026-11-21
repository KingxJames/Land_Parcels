<?php

namespace Modules\LandTracker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentPlan extends Model
{
    use HasUuids;

    protected $fillable = ['parcel_id', 'owner_id', 'total_price', 'deposit', 'monthly_amount', 'start_date', 'grace_months'];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'deposit' => 'decimal:2',
            'monthly_amount' => 'decimal:2',
            'start_date' => 'date:Y-m-d',
            'grace_months' => 'integer',
        ];
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function instalments(): HasMany
    {
        return $this->hasMany(Instalment::class, 'plan_id')->orderBy('seq');
    }

    public function hasPayments(): bool
    {
        return $this->instalments()->where('amount_paid', '>', 0)->exists();
    }

    // Totals for screens: paid, still owed, and what is overdue today
    public function summary(): array
    {
        $rows = $this->instalments;
        $cents = fn ($v) => (int) round(((float) $v) * 100);
        $today = now()->toDateString();

        $total = $rows->sum(fn ($i) => $cents($i->amount));
        $paid = $rows->sum(fn ($i) => $cents($i->amount_paid));
        $overdue = $rows->filter(fn ($i) => $i->due_date->toDateString() < $today)
            ->sum(fn ($i) => $cents($i->amount) - $cents($i->amount_paid));
        $next = $rows->first(fn ($i) => $cents($i->amount_paid) < $cents($i->amount));

        return [
            'total' => number_format($total / 100, 2, '.', ''),
            'paid' => number_format($paid / 100, 2, '.', ''),
            'outstanding' => number_format(($total - $paid) / 100, 2, '.', ''),
            'overdue' => number_format($overdue / 100, 2, '.', ''),
            'next_due' => $next ? ['label' => $next->label, 'due_date' => $next->due_date->toDateString(),
                'amount_left' => number_format(($cents($next->amount) - $cents($next->amount_paid)) / 100, 2, '.', '')] : null,
            'paid_off' => $total === $paid,
            // Confirmed money not yet used, such as an overpayment
            'credit' => \Modules\LandTracker\Services\PaymentAllocator::creditFor($this->parcel_id, $this->owner_id),
        ];
    }
}
