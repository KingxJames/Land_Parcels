<?php

namespace Modules\LandTracker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParcelOutline extends Model
{
    const CREATED_AT = null; // table only has updated_at

    protected $primaryKey = 'parcel_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['parcel_id', 'points', 'updated_by'];

    protected function casts(): array
    {
        return ['points' => 'array'];
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Rough size in square feet, from the shape and the land's real plan size.
    // Null when the land has no width and height set.
    public function estimatedSqft(?Land $land = null): ?float
    {
        $land ??= $this->parcel?->land;
        if (! $land?->plan_width_ft || ! $land?->plan_height_ft) {
            return null;
        }

        // Area in "percent squared", scaled by the real feet each percent covers
        $scale = ((float) $land->plan_width_ft / 100) * ((float) $land->plan_height_ft / 100);

        return round(self::area($this->points) * $scale, 2);
    }

    // Shoelace formula on [{x, y}, ...]
    public static function area(array $points): float
    {
        $sum = 0.0;
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            $a = $points[$i];
            $b = $points[($i + 1) % $n];
            $sum += $a['x'] * $b['y'] - $b['x'] * $a['y'];
        }

        return abs($sum) / 2;
    }
}
