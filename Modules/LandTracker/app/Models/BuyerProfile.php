<?php

namespace Modules\LandTracker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerProfile extends Model
{
    // ID types and sources of funds are each company's own lists (CompanySettings)

    // Fields that, if changed, mean the profile must be checked again
    public const CHECKED_FIELDS = ['id_type', 'id_number_encrypted', 'id_file_path', 'home_address', 'source_of_funds'];

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'id_type', 'id_number_encrypted', 'id_file_path', 'home_address', 'source_of_funds'];

    // Never send the full ID number or the storage path to the browser
    protected $hidden = ['id_number_encrypted', 'id_file_path'];

    protected $appends = ['id_number_masked', 'has_id_file', 'is_verified'];

    protected function casts(): array
    {
        return [
            'id_number_encrypted' => 'encrypted', // uses APP_KEY; keep that key backed up
            'verified_at' => 'datetime',
        ];
    }

    // Any edit to checked details wipes the old verification
    protected static function booted(): void
    {
        static::saving(function (BuyerProfile $profile) {
            if ($profile->exists && $profile->isDirty(self::CHECKED_FIELDS) && ! $profile->isDirty('verified_at')) {
                $profile->verified_by = null;
                $profile->verified_at = null;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getIdNumberMaskedAttribute(): ?string
    {
        $n = $this->id_number_encrypted;

        return $n ? str_repeat('•', max(strlen($n) - 4, 0)) . substr($n, -4) : null;
    }

    public function getHasIdFileAttribute(): bool
    {
        return (bool) $this->id_file_path;
    }

    public function getIsVerifiedAttribute(): bool
    {
        return $this->verified_at !== null;
    }
}
