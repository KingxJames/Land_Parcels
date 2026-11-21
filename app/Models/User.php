<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, HasUuids, Notifiable;

    // is_platform_admin is left out on purpose: set it with forceFill, never from a form
    protected $fillable = [
        'org_id', 'full_name', 'email', 'password', 'google_id', 'avatar', 'status', 'phone', 'last_sign_in_at',
    ];

    protected $hidden = ['password', 'remember_token', 'google_id'];

    protected function casts(): array
    {
        return [
            'is_platform_admin' => 'boolean',
            'last_sign_in_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    // Emails are stored lower-case so lookups match
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower(trim($value));
    }

    // Switching someone off signs them out everywhere
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('status') && $user->status === 'switched_off') {
                $user->tokens()->delete();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function buyerProfile(): HasOne
    {
        return $this->hasOne(\Modules\LandTracker\Models\BuyerProfile::class);
    }

    // You, the platform owner: sees every company, manages billing
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    // Office staff of a company: any role except Owner (or the platform owner)
    public function isStaff(): bool
    {
        return $this->isPlatformAdmin() || array_diff($this->companyRoleNames(), ['owner']) !== [];
    }

    public function hasCompanyRole(string $name): bool
    {
        return in_array($name, $this->companyRoleNames(), true);
    }

    // Role names in the person's own company. Reads the table directly, so it works
    // the same in web requests, queued jobs and commands, whatever company is "current".
    public function companyRoleNames(): array
    {
        if (! $this->org_id || ! $this->exists) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('model_has_roles as m')
            ->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.model_id', $this->id)->where('m.model_type', self::class)->where('m.team_id', $this->org_id)
            ->pluck('r.name')->all();
    }
}
