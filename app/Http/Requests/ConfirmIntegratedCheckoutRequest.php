<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Data\Integrations\IntegratedCheckoutConfirmationInput;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ConfirmIntegratedCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE)
            instanceof CheckoutSessionPrincipal;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'profile' => ['present', 'array:fullName,birthDate,gender,educationLevel,intendedField,phone'],
            'profile.fullName' => ['sometimes', 'required', 'string', 'min:2', 'max:200'],
            'profile.birthDate' => ['sometimes', 'required', 'date_format:Y-m-d', 'before:today'],
            'profile.gender' => ['sometimes', 'required', 'string', Rule::in(['FEMALE', 'MALE'])],
            'profile.educationLevel' => ['sometimes', 'required', 'string', 'max:64'],
            'profile.intendedField' => ['sometimes', 'required', 'string', Rule::in([
                'KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM',
            ])],
            'profile.phone' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{7,30}$/'],
            'consents' => ['present', 'array:psychotest,dass'],
            'consents.psychotest' => ['sometimes', 'required', 'array:accepted,documentVersion,documentHash'],
            'consents.dass' => ['sometimes', 'required', 'array:accepted,documentVersion,documentHash'],
            'consents.psychotest.accepted' => ['required_with:consents.psychotest', 'boolean'],
            'consents.dass.accepted' => ['required_with:consents.dass', 'boolean'],
            'consents.psychotest.documentVersion' => ['required_with:consents.psychotest', 'string', 'max:64'],
            'consents.dass.documentVersion' => ['required_with:consents.dass', 'string', 'max:64'],
            'consents.psychotest.documentHash' => ['required_with:consents.psychotest', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'consents.dass.documentHash' => ['required_with:consents.dass', 'string', 'regex:/^[0-9a-f]{64}$/D'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['profile', 'consents']) !== []) {
                $validator->errors()->add('_schema', 'Payload memuat field yang tidak didukung.');
            }
            $consents = $this->input('consents');
            foreach (['psychotest', 'dass'] as $type) {
                if (is_array($consents) && array_key_exists($type, $consents)
                    && $this->input("consents.{$type}.accepted") !== true) {
                    $validator->errors()->add("consents.{$type}.accepted", 'Persetujuan wajib diberikan secara eksplisit.');
                }
            }
        });
    }

    public function toInput(): IntegratedCheckoutConfirmationInput
    {
        $principal = $this->attributes->get(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE);
        if (! $principal instanceof CheckoutSessionPrincipal) {
            abort(403);
        }
        $input = $this->validated();
        $profile = $input['profile'] ?? null;
        $consents = $input['consents'] ?? null;
        if (! is_array($profile) || ! is_array($consents)) {
            abort(422);
        }

        return new IntegratedCheckoutConfirmationInput($principal, $profile, $consents);
    }

    protected function prepareForValidation(): void
    {
        $fullName = $this->input('profile.fullName');
        if (is_string($fullName)) {
            $profile = $this->input('profile');
            if (is_array($profile)) {
                $profile['fullName'] = preg_replace('/\s+/u', ' ', trim($fullName));
                $this->merge(['profile' => $profile]);
            }
        }
    }
}
