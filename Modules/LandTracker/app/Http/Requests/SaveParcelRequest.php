<?php

namespace Modules\LandTracker\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\LandTracker\Enums\ParcelKind;
use Modules\LandTracker\Models\Parcel;

class SaveParcelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isStaff();
    }

    public function rules(): array
    {
        $parcel = $this->route('parcel');
        $landId = $parcel?->land_id ?? $this->route('land')?->id;
        $req = $parcel ? 'sometimes' : 'required';

        return [
            'label' => [$req, 'string', 'max:100',
                Rule::unique('parcels')->where('land_id', $landId)->ignore($parcel?->id)],
            'kind' => [$req, Rule::enum(ParcelKind::class)],
            'size_sqft' => [$req, 'numeric', 'gt:0', 'max:999999999999'],
            'price' => [$req, 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'description' => ['nullable', 'string', 'max:5000'],
            'beds' => ['nullable', 'integer', 'min:0', 'max:100'],
            'baths' => ['nullable', 'numeric', 'min:0', 'max:99.5', 'decimal:0,1'],
            'floor_area_sqft' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'year_built' => ['nullable', 'integer', 'between:1800,' . (now()->year + 5)],
            // Not allowed here; use reserve, release and sell
            'status' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'hold_until' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.prohibited' => 'Use reserve, release or sell to change the status.',
            'owner_id.prohibited' => 'Use reserve or sell to set the owner.',
            'hold_until.prohibited' => 'Use reserve to set the hold date.',
        ];
    }

    // Home details make no sense on a plain lot
    public function after(): array
    {
        return [function (Validator $v) {
            $kind = $this->input('kind', $this->route('parcel')?->kind?->value);
            if ($kind !== ParcelKind::Land->value) {
                return;
            }
            foreach (Parcel::HOUSE_FIELDS as $f) {
                if ($this->filled($f)) {
                    $v->errors()->add($f, 'Only houses have ' . str_replace('_', ' ', $f) . '.');
                }
            }
        }];
    }
}
