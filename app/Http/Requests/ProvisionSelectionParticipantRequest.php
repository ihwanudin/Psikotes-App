<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProvisionSelectionParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return is_string($this->attributes->get('selection_client_id'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'externalCandidateId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'selectionRoundId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'registrationId' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'fullName' => ['required', 'string', 'min:2', 'max:200'],
            'birthDate' => ['required', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['required', Rule::in(['female', 'male'])],
            'educationLevel' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{7,30}$/'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        if (! is_string($key)
            || strlen($key) > 200
            || ! preg_match('/^psychotest-participant:v1:[A-Za-z0-9_-]+$/', $key)) {
            return null;
        }

        return $key;
    }
}
