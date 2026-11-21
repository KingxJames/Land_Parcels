<?php

namespace Modules\LandTracker\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['file_path'];

    protected $appends = ['is_public'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_with');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getIsPublicAttribute(): bool
    {
        return $this->shared_with === null;
    }

    // Public documents about a parcel on a hidden land stay hidden too
    public function visibleTo(?User $user): bool
    {
        if ($user?->isStaff() && \App\Support\Tenancy::allows($user, $this)) {
            return true;
        }
        if ($this->shared_with !== null) {
            return $user !== null && $this->shared_with === $user->id;
        }

        if ($this->organization?->isSuspended()) {
            return false;
        }

        return $this->parcel_id === null || (bool) $this->parcel?->land?->isListedPublicly();
    }
}
