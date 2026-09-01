<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReviewAssessmentBillTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') instanceof Admin;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'proof_fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'decision' => ['required', 'string', Rule::enum(AssessmentBillManualDecision::class)],
            'rejection_code' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('decision') === AssessmentBillManualDecision::Reject->value),
                Rule::prohibitedIf(fn (): bool => $this->input('decision') === AssessmentBillManualDecision::Approve->value),
                'string',
                Rule::enum(AssessmentBillManualRejectionCode::class),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('_schema', 'Payload memuat field yang tidak didukung.');
            }
        });
    }

    protected function failedAuthorization(): never
    {
        throw new NotFoundHttpException;
    }
}
