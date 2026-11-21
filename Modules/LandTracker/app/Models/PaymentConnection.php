<?php

namespace Modules\LandTracker\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentConnection extends Model
{
    use HasUuids;

    public const PROVIDERS = ['ekyash', 'card_gateway', 'digiwallet'];

    public $timestamps = false;

    protected $fillable = ['org_id', 'provider', 'merchant_id', 'api_key_encrypted', 'callback_secret_encrypted', 'mode'];

    // Keys never leave the server
    protected $hidden = ['api_key_encrypted', 'callback_secret_encrypted'];

    protected $appends = ['api_key_hint', 'has_callback_secret'];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',          // uses APP_KEY
            'callback_secret_encrypted' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(PaymentNotice::class, 'connection_id');
    }

    // The payment method a confirmed notice from this provider becomes
    public function payMethod(): string
    {
        return match ($this->provider) {
            'ekyash' => 'ekyash',
            'digiwallet' => 'digiwallet',
            'card_gateway' => \App\Support\CompanySettings::GATEWAY_ONLY,
        };
    }

    public function getApiKeyHintAttribute(): ?string
    {
        $k = $this->api_key_encrypted;

        return $k ? '••••' . substr($k, -4) : null;
    }

    public function getHasCallbackSecretAttribute(): bool
    {
        return (bool) $this->callback_secret_encrypted;
    }
}
