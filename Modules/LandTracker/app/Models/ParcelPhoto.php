<?php

namespace Modules\LandTracker\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParcelPhoto extends Model
{
    use HasUuids;

    protected $fillable = ['parcel_id', 'file_path', 'sort_order'];

    protected $hidden = ['file_path'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function getUrlAttribute(): string
    {
        return url("/api/v1/parcel-photos/{$this->id}");
    }
}
