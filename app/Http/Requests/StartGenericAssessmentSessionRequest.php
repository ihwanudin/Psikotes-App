<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * F2 S5 (2026-09-21). ADR-0030's participant start request: only the four generic
 * instruments, no scope selector of any kind, and a closed 422
 * INVALID_ASSESSMENT_START_REQUEST envelope on failure. Deliberately a separate
 * class from StartAssessmentSessionRequest (the pre-existing, still-used-by-the-
 * integrated-test-route request that still allows dass21 and the default Laravel
 * validation envelope) rather than editing that one: the two start routes now have
 * genuinely different contracts, and narrowing the shared request would have
 * silently changed the integrated route's dass21 behaviour and its currently-green
 * test assertions.
 */
final class StartGenericAssessmentSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Middleware authenticates; the controller/command own authorization.
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return ['testType' => $this->route('testType')];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['testType' => ['required', 'string', Rule::in(['ist', 'papi', 'rmib', 'kraepelin'])]];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Assessment scope comes only from the credential. dass21 is rejected
            // above by the enum rule (it has an isolated storage/lifecycle per
            // API_CONTRACT.md, not the generic four-instrument command).
            $selectors = ['assessment_participant_id', 'assessmentParticipantId', 'assessment_attempt_id', 'assessmentAttemptId',
                'participant_id', 'participantId', 'organization_id', 'organizationId'];
            if (array_intersect(array_keys($this->all()), $selectors) !== []) {
                $validator->errors()->add('scope', 'Scope tes harus berasal dari kredensial, bukan input permintaan.');
            }
        }];
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'INVALID_ASSESSMENT_START_REQUEST',
                'message' => 'Permintaan mulai tes tidak valid.',
            ],
        ], 422));
    }
}
