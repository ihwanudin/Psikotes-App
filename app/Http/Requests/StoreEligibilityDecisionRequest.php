<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEligibilityDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'levels' => ['required', 'array', 'size:18'],
            'field_code' => ['required', 'string'],
            'iq' => ['required', 'integer', 'min:1', 'max:300'],
            'validity' => ['required', 'string', 'in:V1,V2,V3'],
            'standard_configuration' => ['required', 'array'],
            'eligibility_source_versions' => ['required', 'array', 'size:5'],
        ];
    }
}
