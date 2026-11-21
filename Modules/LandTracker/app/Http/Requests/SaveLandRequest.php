<?php

namespace Modules\LandTracker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveLandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStaff();
    }

    // Company staff always work in their own company; only the platform owner picks one
    protected function prepareForValidation(): void
    {
        if ($org = \App\Support\Tenancy::scope($this->user())) {
            $this->merge(['org_id' => $org]);
        }
    }

    public function rules(): array
    {
        // On update every field is optional; on create the basics are required
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'org_id' => [$req, 'uuid', 'exists:organizations,id'],
            'name' => [$req, 'string', 'max:150'],
            'place' => [$req, 'string', 'max:150'],
            'site_plan' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:' . config('platform.upload_kb.site_plan')],
            'plan_width_ft' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'plan_height_ft' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
