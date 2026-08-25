<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreParticipantRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            '_registration_token' => [
                'bail',
                'required',
                'uuid',
                Rule::in([(string) $this->session()->get('registration.token')]),
            ],
            'package_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('packages', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereNotNull('amount')
                        ->where('amount', '>', 0)
                        ->where('currency', 'IDR'),
                ),
            ],
            'full_name' => ['bail', 'required', 'string', 'min:2', 'max:200'],
            'gender' => ['bail', 'required', Rule::in(['female', 'male'])],
            'birth_date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'education_level' => ['bail', 'required', 'string', 'min:2', 'max:64'],
            'intended_field' => [
                'bail',
                'required',
                Rule::in(['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM']),
            ],
            'phone' => ['bail', 'required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{6,30}[0-9]$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'consent_psychotest' => ['bail', 'required', 'accepted'],
            'consent_dass' => ['bail', 'required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => is_string($this->input('full_name'))
                ? preg_replace('/\s+/u', ' ', trim($this->input('full_name')))
                : $this->input('full_name'),
            'email' => $this->input('email') === '' ? null : $this->input('email'),
        ]);
    }
}
