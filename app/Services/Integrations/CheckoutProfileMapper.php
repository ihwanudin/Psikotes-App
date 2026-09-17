<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Data\Integrations\CheckoutProfile;
use App\Models\Participant;
use DateTimeImmutable;
use DomainException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use SensitiveParameter;

/**
 * Pure profile projection, not an authority entrypoint. No queries, writes or RLS elevation.
 * Eventual caller must revalidate the checkout lifecycle and supply its authorized, fully
 * loaded participant graph and authoritative calendar date inside that boundary. A stale
 * principal or caller-selected model does not become authorized by passing through here.
 */
final readonly class CheckoutProfileMapper
{
    public function __construct(private Factory $validator) {}

    public function map(#[SensitiveParameter] Participant $participant, DateTimeImmutable $asOf): CheckoutProfile
    {
        // Read only raw allowlisted scalar attributes: never serialize a model or load its relations.
        $attributes = $participant->getAttributes();
        $profile = [];
        foreach (['full_name', 'birth_date', 'gender', 'education_level', 'intended_field', 'email', 'phone'] as $field) {
            if (! array_key_exists($field, $attributes)) {
                throw new DomainException('CHECKOUT_PROFILE_UNAVAILABLE');
            }
            $value = $attributes[$field];
            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                throw new DomainException('CHECKOUT_PROFILE_UNAVAILABLE');
            }
            $profile[$field] = $value;
        }

        // Checkout-v2 ingress criteria, with persisted lowercase gender and Eloquent's SQL date form.
        // Do not import the stricter education minimum / phone ending rule from public registration.
        if ($this->validator->make($profile, [
            'full_name' => ['nullable', 'string', 'min:2', 'max:200'],
            'birth_date' => ['nullable', 'date_format:Y-m-d,Y-m-d H:i:s', 'before:'.$asOf->format('Y-m-d')],
            'gender' => ['nullable', 'string', Rule::in(['female', 'male'])],
            'education_level' => ['nullable', 'string', 'max:64'],
            'intended_field' => ['nullable', 'string', Rule::in(['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'])],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9 ()-]{7,30}$/'],
        ])->fails()) {
            throw new DomainException('CHECKOUT_PROFILE_UNAVAILABLE');
        }

        return new CheckoutProfile(
            fullName: $profile['full_name'],
            // Calendar text is validated before slicing: never roll over an invalid date or convert UTC.
            birthDate: $profile['birth_date'] === null ? null : substr($profile['birth_date'], 0, 10),
            gender: $profile['gender'],
            educationLevel: $profile['education_level'],
            intendedField: $profile['intended_field'],
            email: $profile['email'],
            phone: $profile['phone'],
        );
    }
}
