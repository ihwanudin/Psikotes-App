<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class StoreParticipantRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            '_registration_token' => [
                'bail',
                'required',
                'uuid',
                Rule::in([(string) $this->session()->get('registration.token')]),
            ],
            'package_id' => [
                'bail',
                'required',
                'integer',
                // Not Rule::exists('packages', 'id')->where(...): that
                // builds a lazy rule object whose actual query only runs
                // later, inside the validator's own
                // DatabasePresenceVerifier -- wrapping *this* method in
                // runAsService() would do nothing, the query still runs
                // outside any RLS context. A closure rule runs the query
                // itself, so the runAsService() call has to be inside it.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->assertPackageAvailable($value, $fail);
                },
            ],
            'payment_method_code' => [
                'bail',
                Rule::requiredIf(fn (): bool => $this->paymentRequired()),
                'nullable',
                'string',
                'max:48',
                'regex:/^[a-z0-9_]+$/',
            ],
            'full_name' => ['bail', 'required', 'string', 'min:2', 'max:200'],
            'gender' => ['bail', 'required', Rule::in(['female', 'male'])],
            'birth_date' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'education_level' => ['bail', 'required', 'string', 'min:2', 'max:64'],
            'intended_field' => [
                'bail',
                'required',
                Rule::in(['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM']),
            ],
            'phone' => ['bail', 'required', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{6,30}[0-9]$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'consent_psychotest' => ['bail', 'required', 'accepted'],
            'consent_dass' => ['bail', 'required', 'accepted'],
            'include_consultation' => ['bail', 'required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_method_code.required' => 'Metode pembayaran wajib dipilih.',
            'payment_method_code.regex' => 'Metode pembayaran tidak tersedia.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'full_name' => is_string($this->input('full_name'))
                ? preg_replace('/\s+/u', ' ', trim($this->input('full_name')))
                : $this->input('full_name'),
            'email' => $this->input('email') === '' ? null : $this->input('email'),
            'include_consultation' => $this->boolean('include_consultation'),
        ]);
    }

    private function paymentRequired(): bool
    {
        // Rule::requiredIf()'s closure, like the package_id rule above, is
        // evaluated lazily by the validator -- runAsService() has to wrap
        // the query itself, not the method that returns the closure.
        $package = app(RlsContextRunner::class)->runAsService(
            fn () => TestPackage::query()
                ->availableForRegistration()
                ->find($this->integer('package_id')),
        );

        if ($package === null) {
            return true;
        }

        return (int) $package->amount
            + ($this->boolean('include_consultation') ? ($package->consultation_amount ?? 0) : 0)
            > 0;
    }

    private function assertPackageAvailable(mixed $value, Closure $fail): void
    {
        // Same availability conditions Rule::exists('packages', 'id')
        // used to check inline -- deliberately NOT
        // TestPackage::availableForRegistration() (that scope also
        // requires a dass21 item plus one of {code === 'DASS21', a
        // non-dass21 item}, a stricter check paymentRequired() above
        // already applies separately; widening this rule to match would
        // change validation behavior beyond this RLS fix's scope).
        $exists = app(RlsContextRunner::class)->runAsService(
            fn (): bool => DB::table('packages')
                ->where('id', $value)
                ->where('is_active', true)
                ->whereNotNull('amount')
                ->where('amount', '>=', 0)
                ->where('currency', 'IDR')
                ->exists(),
        );

        if (! $exists) {
            $fail('Paket tidak ditemukan atau tidak tersedia.');
        }
    }
}
