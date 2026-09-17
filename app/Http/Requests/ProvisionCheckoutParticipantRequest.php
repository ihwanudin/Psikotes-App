<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use App\Services\Integrations\CheckoutContractAdapter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ProvisionCheckoutParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('integration_client') instanceof IntegrationClient;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'contractVersion' => ['required', 'string', Rule::in([CheckoutContractAdapter::VERSION])],
            'sourceSystem' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/'],
            'organizationCode' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'assessmentPackageCode' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'externalCandidateId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'externalProcessId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'externalRegistrationId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'assessmentRoundId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'payerType' => ['nullable', 'string', Rule::in(['self', 'organization'])],
            'fundingMode' => ['sometimes', 'required', 'string', Rule::in(['COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'])],
            'profile' => ['present', 'array:fullName,birthDate,gender,educationLevel,email,phone,intendedField'],
            'profile.fullName' => ['nullable', 'string', 'min:2', 'max:200'],
            'profile.birthDate' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'profile.gender' => ['nullable', 'string', Rule::in(['FEMALE', 'MALE'])],
            'profile.educationLevel' => ['nullable', 'string', 'max:64'],
            'profile.intendedField' => ['nullable', 'string', Rule::in(['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'])],
            'profile.email' => ['nullable', 'email:rfc', 'max:255'],
            'profile.phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{7,30}$/'],
            'metadata' => ['nullable', 'array:cohortCode'],
            'metadata.cohortCode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rootKeys = array_filter(array_keys($this->rules()), static fn (string $key): bool => ! str_contains($key, '.'));
            if (array_diff(array_keys($this->all()), $rootKeys) !== []) {
                $validator->errors()->add('_schema', 'Payload memuat field yang tidak didukung.');
            }
            if (array_key_exists('payerType', $this->all()) && array_key_exists('fundingMode', $this->all())) {
                $validator->errors()->add('payerType', 'Kirim payerType atau fundingMode, bukan keduanya.');
            }
        });
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && strlen($key) <= 200 && preg_match('/^[A-Za-z0-9:._-]+$/', $key) ? $key : null;
    }
}
