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

    public function isStaff(): bool
    {
        return in_array($this->role, ['super_admin', 'office_admin']);
    }
}