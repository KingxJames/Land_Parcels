<?php

namespace Modules\LandTracker\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\LandTracker\Enums\EnquiryStatus;

class Enquiry extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'parcel_id', 'name', 'phone', 'email', 'message'];

    protected function casts(): array
    {
        return ['status' => EnquiryStatus::class];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }
}
