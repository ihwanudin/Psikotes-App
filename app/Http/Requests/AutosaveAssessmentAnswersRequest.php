<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * F2 session-http (2026-09-21). Transport-layer size/shape guard for
 * POST /sessions/{id}/answers, sitting in front of the already-tested
 * AssessmentAutosavePolicy -- it narrows nothing the policy accepts, it
 * only bounds size and recursion so a client cannot force the server to
 * hash/iterate an arbitrarily large or deep payload before the domain
 * layer ever sees it. The exact per-session item_no bound stays a domain
 * rule in the policy (already enforced, see AssessmentAutosavePolicy);
 * this class only enforces a static, session-independent ceiling.
 *
 * `value` shape: every real scoring reader that exists today
 * (ScoreSealedIstAnswerSet, ScoreSealedPapiAnswerSet,
 * ScoreSealedRmibAnswerSet) requires `value` to be a plain string --
 * RMIB specifically requires a one- or two-digit rank string "1".."12".
 * No shipped instrument currently reads an object-shaped value. The
 * policy's own test suite (AssessmentAutosavePolicyTest,
 * AutosaveAssessmentAnswersTest, AssessmentSessionAutosaveActionTest)
 * nonetheless already accepts one level of associative-array nesting
 * with scalar leaves (e.g. ['rank' => 3], ['choice' => 'A']) as a
 * currently-green contract, so this class permits exactly that -- a
 * scalar, or a shallow (depth-1) associative array of scalar leaves --
 * and rejects anything deeper or a bare list. A value this class accepts
 * but no instrument reads is not silently corrupted: every scoring
 * reader above fails closed (throws, never guesses) on a non-string
 * value, so an object-shaped answer for an instrument that doesn't use
 * one simply cannot be scored, it is not misscored.
 *
 * Numbers, with reasoning:
 * - MAX_VALUE_STRING_LENGTH (64): the only known real values are short
 *   codes (RMIB "1".."12"; PAPI/IST single-letter or short choice
 *   codes). 64 is generous headroom over any known real answer while
 *   still blocking a client from stuffing megabytes into one leaf.
 * - MAX_VALUE_OBJECT_KEYS (16) / MAX_VALUE_KEY_LENGTH (64): no shipped
 *   instrument reads an object value at all today; the tested shapes use
 *   1-2 keys. 16 short keys leaves room for a richer future answer shape
 *   without allowing an unbounded object.
 * - MAX_ITEMS_PER_REQUEST (300): PAPI is fixed at 90 items
 *   (ScoreSealedPapiAnswerSet::ITEM_COUNT) and RMIB at 108
 *   (ScoreSealedRmibAnswerSet::GROUP_COUNT * POSITIONS_PER_GROUP). IST's
 *   total is schema-driven per session_definition rather than a single
 *   fixed constant, so no exact canonical ceiling could be located for
 *   it; 300 is set as a generous multiple of the largest known real
 *   total (RMIB's 108) to leave headroom while still bounding a single
 *   request's size. The exact per-session ceiling (this session's real
 *   total item count) is enforced separately and precisely by
 *   AssessmentAutosavePolicy's maxItemNo check.
 */
final class AutosaveAssessmentAnswersRequest extends FormRequest
{
    private const MAX_VALUE_STRING_LENGTH = 64;

    private const MAX_VALUE_OBJECT_KEYS = 16;

    private const MAX_VALUE_KEY_LENGTH = 64;

    private const MAX_ITEMS_PER_REQUEST = 300;

    public function authorize(): bool
    {
        // Middleware authenticates; the action owns session ownership/authorization.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mutation_id' => ['required', 'string'],
            'revision' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS_PER_REQUEST],
            'items.*' => ['required', 'array', 'size:2'],
            'items.*.item_no' => ['required', 'integer', 'min:1'],
            'items.*.value' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->validateValueShape($value, $fail);
                },
            ],
        ];
    }

    private function validateValueShape(mixed $value, Closure $fail): void
    {
        if ($this->isValidScalarLeaf($value)) {
            return;
        }

        if (! is_array($value) || array_is_list($value)) {
            $fail('Bentuk nilai jawaban tidak valid.');

            return;
        }

        if (count($value) > self::MAX_VALUE_OBJECT_KEYS) {
            $fail('Jumlah field nilai jawaban melebihi batas.');

            return;
        }

        foreach ($value as $key => $leaf) {
            if (! is_string($key) || $key === '' || mb_strlen($key) > self::MAX_VALUE_KEY_LENGTH) {
                $fail('Nama field nilai jawaban tidak valid.');

                return;
            }
            if (! $this->isValidScalarLeaf($leaf)) {
                $fail('Nilai jawaban bersarang melebihi kedalaman yang diizinkan.');

                return;
            }
        }
    }

    private function isValidScalarLeaf(mixed $value): bool
    {
        if (is_bool($value) || is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (is_string($value)) {
            return mb_strlen($value) <= self::MAX_VALUE_STRING_LENGTH;
        }

        return false;
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'INVALID_ANSWER_BATCH',
                'message' => 'Permintaan autosave jawaban tidak valid.',
            ],
        ], 422));
    }
}
