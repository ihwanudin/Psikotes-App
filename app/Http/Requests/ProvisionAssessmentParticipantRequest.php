<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\IntegrationClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ProvisionAssessmentParticipantRequest extends FormRequest
{
    private const array TOP_LEVEL_KEYS = [
        'sourceSystem', 'externalCandidateId', 'externalProcessId', 'externalRegistrationId',
        'assessmentRoundId', 'organizationCode', 'assessmentPackageCode', 'fundingMode',
        'profile', 'metadata',
    ];

    private const array PROFILE_KEYS = [
        'fullName', 'birthDate', 'gender', 'educationLevel', 'email', 'phone',
    ];

    public function authorize(): bool
    {
        return $this->attributes->get('integration_client') instanceof IntegrationClient;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sourceSystem' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/'],
            'externalCandidateId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'externalProcessId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'externalRegistrationId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'assessmentRoundId' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'organizationCode' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'assessmentPackageCode' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/'],
            'fundingMode' => ['required', Rule::in(['COMMERCIAL_SELF_PAY', 'SPONSORED', 'INVOICED_TO_ORGANIZATION', 'INTERNAL', 'WAIVED'])],
            'profile' => ['required', 'array'],
            'profile.fullName' => ['required', 'string', 'min:2', 'max:200'],
            'profile.birthDate' => ['required', 'date_format:Y-m-d', 'before:today'],
            'profile.gender' => ['required', Rule::in(['FEMALE', 'MALE'])],
            'profile.educationLevel' => ['required', 'string', 'max:64'],
            'profile.email' => ['nullable', 'email:rfc', 'max:255'],
            'profile.phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{7,30}$/'],
            'metadata' => ['nullable', 'array'],
            'metadata.cohortCode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), self::TOP_LEVEL_KEYS);
            $profile = $this->input('profile');
            $unknownProfile = is_array($profile) ? array_diff(array_keys($profile), self::PROFILE_KEYS) : [];
            $metadata = $this->input('metadata');
            $allowedMetadata = (array) config('assessment_integration.metadata_keys', ['cohortCode']);
            $unknownMetadata = is_array($metadata) ? array_diff(array_keys($metadata), $allowedMetadata) : [];

            if ($unknown !== [] || $unknownProfile !== [] || $unknownMetadata !== []) {
                $validator->errors()->add('_schema', 'Payload memuat field yang tidak didukung.');
            }
        });
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && strlen($key) <= 200 && preg_match('/^[A-Za-z0-9:._-]+$/', $key)
            ? $key
            : null;
    }
}
