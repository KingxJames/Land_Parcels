<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\LandTracker\Models\Land;

// Each company that subscribes. Every other record belongs to one.
class Organization extends Model
{
    use HasUuids;

    public const STATUSES = ['trial', 'active', 'suspended'];

    protected $fillable = [
        'name', 'slug', 'currency', 'theme', 'phone', 'whatsapp', 'email', 'address',
        'logo_path', 'receipt_prefix', 'reminder_days_before',
    ]; // status (platform owner) and settings (CompanySettings rules) are set on purpose, not mass-filled

    protected $hidden = ['logo_path', 'settings'];

    protected $attributes = ['currency' => 'BZD', 'theme' => 'default', 'status' => 'trial', 'reminder_days_before' => 3];

    protected function casts(): array
    {
        return ['reminder_days_before' => 'integer', 'settings' => 'array'];
    }

    // Fill in a slug and receipt prefix when none is given
    protected static function booted(): void
    {
        static::creating(function (Organization $o) {
            $o->slug ??= self::uniqueSlug($o->name);
            $o->receipt_prefix ??= self::uniquePrefix($o->slug);
        });

        // Every company starts with Super admin, Office admin, Cashier and Owner
        static::created(fn (Organization $o) => \App\Support\DefaultRoles::createFor($o));
        static::saved(fn (Organization $o) => \App\Support\CompanySettings::forget($o->id));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    public function lands(): HasMany
    {
        return $this->hasMany(Land::class, 'org_id');
    }

    public function settings(): \App\Support\CompanySettings
    {
        return \App\Support\CompanySettings::for($this);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $base = substr($base, 0, 50);
        $slug = $base;
        for ($i = 2; self::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    // "cayo-ridge" -> "CR-", then "CR2-" and so on if taken
    public static function uniquePrefix(string $slug): string
    {
        $parts = array_filter(explode('-', $slug));
        $base = strtoupper(count($parts) > 1 ? implode('', array_map(fn ($p) => $p[0], $parts)) : substr($slug, 0, 3));
        $base = substr(preg_replace('/[^A-Z0-9]/', '', $base) ?: 'R', 0, 5);
        $prefix = "{$base}-";
        for ($i = 2; self::where('receipt_prefix', $prefix)->exists(); $i++) {
            $prefix = "{$base}{$i}-";
        }

        return $prefix;
    }
}
