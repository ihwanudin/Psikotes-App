<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ExchangeCheckoutSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CheckoutSessionHttpContract::class)->exchangeOriginMatches($this);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'handoffToken' => ['required', 'string', 'regex:/^och1_[0-9a-f]{64}$/D'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->headers->get('Content-Type') !== 'application/x-www-form-urlencoded'
                || $this->query->all() !== []
                || array_keys($this->request->all()) !== ['handoffToken']) {
                $validator->errors()->add('_transport', 'Permintaan tidak valid.');
            }
        });
    }

    public function rawHandoffToken(): string
    {
        $value = $this->validated('handoffToken');
        if (! is_string($value)) {
            throw new HttpResponseException(response('Unprocessable Content', 422));
        }

        return $value;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response('Unprocessable Content', 422));
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response('Forbidden', 403));
    }
}
