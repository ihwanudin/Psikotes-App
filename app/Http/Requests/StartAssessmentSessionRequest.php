<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StartAssessmentSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Middleware authenticates; the controller checks the persisted entitlement.
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
        return ['testType' => ['required', 'string', Rule::in(['ist', 'papi', 'rmib', 'kraepelin', 'dass21'])]];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $input = $this->all();
            // Assessment scope comes only from the credential. Preserve unrelated legacy inputs.
            $selectors = ['assessment_participant_id', 'assessmentParticipantId', 'assessment_attempt_id', 'assessmentAttemptId',
                'participant_id', 'participantId', 'organization_id', 'organizationId'];
            if (($this->attributes->has('assessment_principal') && $input !== [])
                || array_intersect(array_keys($input), $selectors) !== []) {
                $validator->errors()->add('scope', 'Scope tes harus berasal dari kredensial, bukan input permintaan.');
            }
        }];
    }
}
