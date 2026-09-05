<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Strict field schema only; transport and session authentication are separate middleware. */
final class CheckoutPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE)
            instanceof CheckoutSessionPrincipal;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['consultationRequested' => ['present']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_keys($this->all()) !== ['consultationRequested']) {
                $validator->errors()->add('_schema', 'Payload tidak didukung.');
            }
            if (! is_bool($this->input('consultationRequested'))) {
                $validator->errors()->add('consultationRequested', 'Nilai harus boolean.');
            }
        });
    }

    public function consultationRequested(): bool
    {
        $value = $this->validated('consultationRequested');
        if (! is_bool($value)) {
            abort(422);
        }

        return $value;
    }
}
