<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'google_id', 'avatar', 'role', 'status', 'phone',
    ];

    protected $hidden = ['password', 'remember_token'];

    // Switching someone off signs them out everywhere
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('status') && $user->status === 'switched_off') {
                $user->tokens()->delete();
            }
        });
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['super_admin', 'office_admin']);
    }
}