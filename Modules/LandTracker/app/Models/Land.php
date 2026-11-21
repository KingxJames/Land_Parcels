<?php

namespace Modules\LandTracker\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Land extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'name', 'place', 'plan_width_ft', 'plan_height_ft', 'is_public', 'sort_order'];

    // The browser gets has_site_plan and loads the image through the site-plan route
    protected $hidden = ['site_plan_path'];

    protected $appends = ['has_site_plan'];

    protected function casts(): array
    {
        return [
            'plan_width_ft' => 'decimal:2',
            'plan_height_ft' => 'decimal:2',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }

    // Shown on the public site: marked public, and the company isn't suspended
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)
            ->whereHas('organization', fn ($o) => $o->where('status', '<>', 'suspended'));
    }

    public function isListedPublicly(): bool
    {
        return $this->is_public && ! $this->organization?->isSuspended();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getHasSitePlanAttribute(): bool
    {
        return (bool) $this->site_plan_path;
    }
}
