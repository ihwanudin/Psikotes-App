<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ExchangeCheckoutSessionRequest extends FormRequest
{
    private ?string $transportToken = null;

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
            $token = app(CheckoutSessionHttpContract::class)->exactHandoffForm($this);
            if ($token === null) {
                $validator->errors()->add('_transport', 'Permintaan tidak valid.');

                return;
            }
            $this->transportToken = $token;
        });
    }

    public function rawHandoffToken(): string
    {
        if ($this->transportToken === null) {
            throw new HttpResponseException(response('Unprocessable Content', 422));
        }

        return $this->transportToken;
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
