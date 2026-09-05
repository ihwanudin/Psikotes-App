<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class IntegratedCheckoutConfirmationInput
{
    /** @var array<string, string> */
    public array $profile;

    /** @var array<string, array{accepted: true, documentVersion: string, documentHash: string}> */
    public array $consents;

    /** @param array<string, mixed> $profile
     * @param  array<string, mixed>  $consents
     */
    public function __construct(
        public CheckoutSessionPrincipal $principal,
        #[SensitiveParameter] array $profile,
        #[SensitiveParameter] array $consents,
    ) {
        $this->profile = $this->validatedProfile($profile);
        $this->consents = $this->validatedConsents($consents);
    }

    public function requestHash(): string
    {
        $value = ['profile' => $this->profile, 'consents' => $this->consents];
        $sort = function (array $items) use (&$sort): array {
            ksort($items);
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $sort($item);
                }
            }

            return $items;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $profile
     * @return array<string, string>
     */
    private function validatedProfile(array $profile): array
    {
        $profileKeys = ['birthDate', 'educationLevel', 'fullName', 'gender', 'intendedField', 'phone'];
        $supplied = array_keys($profile);
        sort($supplied);
        if (array_diff($supplied, $profileKeys) !== [] || count($supplied) !== count(array_unique($supplied))) {
            throw new InvalidArgumentException('Invalid checkout profile confirmation.');
        }
        $validated = [];
        foreach ($profile as $key => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Invalid checkout profile confirmation.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /** @param array<string, mixed> $consents
     * @return array<string, array{accepted: true, documentVersion: string, documentHash: string}>
     */
    private function validatedConsents(array $consents): array
    {
        $consentKeys = array_keys($consents);
        if (array_diff($consentKeys, ['psychotest', 'dass']) !== []
            || count($consentKeys) !== count(array_unique($consentKeys))) {
            throw new InvalidArgumentException('Invalid checkout consent confirmation.');
        }

        $validated = [];
        foreach (['psychotest', 'dass'] as $type) {
            if (array_key_exists($type, $consents)) {
                $validated[$type] = $this->validatedConsent($consents[$type]);
            }
        }

        return $validated;
    }

    /** @return array{accepted: true, documentVersion: string, documentHash: string} */
    private function validatedConsent(mixed $consent): array
    {
        if (! is_array($consent)) {
            throw new InvalidArgumentException('Invalid checkout consent confirmation.');
        }
        $keys = array_keys($consent);
        sort($keys);
        $version = $consent['documentVersion'] ?? null;
        $hash = $consent['documentHash'] ?? null;
        if ($keys !== ['accepted', 'documentHash', 'documentVersion'] || ($consent['accepted'] ?? null) !== true
            || ! is_string($version) || trim($version) === '' || ! is_string($hash)
            || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            throw new InvalidArgumentException('Invalid checkout consent confirmation.');
        }

        return ['accepted' => true, 'documentVersion' => $version, 'documentHash' => $hash];
    }
}
