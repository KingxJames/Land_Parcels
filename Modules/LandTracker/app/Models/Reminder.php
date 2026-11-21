<?php

namespace Modules\LandTracker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Written only by Modules\LandTracker\Services\ReminderService, after the message went out
class Reminder extends Model
{
    use HasUuids;

    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function instalment(): BelongsTo
    {
        return $this->belongsTo(Instalment::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
