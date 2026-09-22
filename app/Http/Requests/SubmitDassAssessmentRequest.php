<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Lead's explicit requirement, 2026-09-22: validate the response payload's
 * shape at the HTTP boundary before it ever reaches a write, regardless of
 * what the existing DASS RLS permits. Exactly 21 responses, each
 * `{item: 1-21, score: 0-3}`, no duplicate item numbers, every item 1-21
 * covered -- the same invariants `Dass21Scorer::score()` enforces
 * downstream (defense in depth, not a replacement: the scorer is still the
 * domain authority and still checked).
 */
final class SubmitDassAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'responses' => ['required', 'array', 'size:21'],
            'responses.*.item' => ['required', 'integer', 'between:1,21', 'distinct'],
            'responses.*.score' => ['required', 'integer', Rule::in([0, 1, 2, 3])],
        ];
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'INVALID_DASS_RESPONSES',
                'message' => 'Jawaban DASS-21 tidak valid.',
            ],
        ], 422));
    }

    /** @return list<array{item: int, score: int}> */
    public function responses(): array
    {
        /** @var list<array{item: int, score: int}> $responses */
        $responses = $this->validated('responses');

        return $responses;
    }
}
