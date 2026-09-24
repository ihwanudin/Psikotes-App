<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * F7 (2026-09-24). Transport-layer shape guard for `POST /sessions/:id/proctor`
 * (`API_CONTRACT.md`: "foto multipart | log event") -- one route, two
 * mutually exclusive payload shapes, same convention as
 * `AutosaveAssessmentAnswersRequest`: this class only bounds shape/size,
 * the action owns session ownership and the domain-level validation
 * already enforced by `ProctoringEvent`'s constructor (evidence_id format,
 * kind-matches-source).
 *
 * Which shape a request is uses is decided by `isPhotoSubmission()`
 * (presence of the `photo` file), not a discriminator field the client
 * would have to remember to set -- a multipart request with a `photo`
 * file is unambiguously a photo submission.
 */
final class SubmitProctoringEvidenceRequest extends FormRequest
{
    private const MAX_METADATA_KEYS = 16;

    private const MAX_METADATA_KEY_LENGTH = 64;

    private const MAX_METADATA_VALUE_LENGTH = 256;

    public function authorize(): bool
    {
        // Middleware authenticates; the action owns session ownership/authorization.
        return true;
    }

    public function isPhotoSubmission(): bool
    {
        return $this->hasFile('photo');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->isPhotoSubmission()) {
            return [
                'photo' => [
                    'required',
                    'file',
                    'image',
                    'mimes:jpg,jpeg',
                    'max:'.(int) config('proctoring.photo_max_upload_kilobytes', 500),
                    'dimensions:max_width='.(int) config('proctoring.photo_max_width', 480)
                        .',max_height='.(int) config('proctoring.photo_max_height', 360),
                ],
                'capture_kind' => ['required', 'string', Rule::in([
                    'session_start', 'periodic', 'session_submit', 'manual_review_upload',
                ])],
                'sequence' => ['required', 'integer', 'min:1'],
                'client_event_id' => ['required', 'string', 'ulid'],
                'captured_at' => ['nullable', 'date'],
            ];
        }

        return [
            'event_kind' => ['required', 'string'],
            'evidence_source' => ['required', 'string', Rule::in(['CLIENT_OBSERVATION', 'SERVER_FINDING'])],
            'evidence_id' => ['required', 'string', 'max:100'],
            'occurred_at' => ['required', 'date'],
            'client_event_id' => ['nullable', 'string', 'ulid'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'metadata' => ['nullable', 'array', 'max:'.self::MAX_METADATA_KEYS],
            'metadata.*' => ['string', 'max:'.self::MAX_METADATA_VALUE_LENGTH],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            if ($this->isPhotoSubmission() || $this->input('metadata') === null) {
                return;
            }

            foreach (array_keys((array) $this->input('metadata')) as $key) {
                if (! is_string($key) || $key === '' || mb_strlen($key) > self::MAX_METADATA_KEY_LENGTH) {
                    $validator->errors()->add('metadata', 'Nama field metadata tidak valid.');

                    return;
                }
            }
        });
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'INVALID_PROCTORING_SUBMISSION',
                'message' => 'Permintaan bukti proctoring tidak valid.',
            ],
        ], 422));
    }
}
