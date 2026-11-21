<?php

namespace Modules\LandTracker\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Written only by Modules\LandTracker\Services\ReceiptIssuer
class Receipt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['pdf_path'];

    protected function casts(): array
    {
        return ['balance_after' => 'decimal:2', 'sent_at' => 'datetime', 'issued_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
