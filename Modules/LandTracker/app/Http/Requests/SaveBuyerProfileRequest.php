<?php

namespace Modules\LandTracker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\LandTracker\Models\BuyerProfile;

class SaveBuyerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStaff();
    }

    public function rules(): array
    {
        $settings = \App\Support\CompanySettings::for($this->route('user')?->org_id);

        return [
            'id_type' => ['nullable', Rule::in($settings->keys('id_types')), 'required_with:id_number'],
            'id_number' => ['nullable', 'string', 'max:64', 'required_with:id_type'],
            'id_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:' . config('platform.upload_kb.id_scan')],
            'home_address' => ['nullable', 'string', 'max:500'],
            'source_of_funds' => ['nullable', Rule::in($settings->keys('fund_sources'))],
        ];
    }
}
