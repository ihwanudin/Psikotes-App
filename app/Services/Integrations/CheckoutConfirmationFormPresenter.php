<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Registration\ConsentDocument;

/** Builds the non-authoritative P16 form descriptor from an already-authorized P14 summary. */
final readonly class CheckoutConfirmationFormPresenter
{
    /** @var list<string> */
    private const array PROFILE_KEYS = [
        'fullName', 'birthDate', 'gender', 'educationLevel', 'intendedField', 'email', 'phone',
    ];

    /** @var array<string, array<string, mixed>> */
    private const array CONTROLS = [
        'fullName' => ['control' => 'text', 'autocomplete' => 'name'],
        'birthDate' => ['control' => 'date'],
        'gender' => ['control' => 'select', 'options' => [
            ['value' => 'FEMALE', 'label' => 'Perempuan'],
            ['value' => 'MALE', 'label' => 'Laki-laki'],
        ]],
        'educationLevel' => ['control' => 'text', 'autocomplete' => 'education-level'],
        'intendedField' => ['control' => 'select', 'options' => [
            ['value' => 'KAIGO', 'label' => 'Kaigo / perawatan'],
            ['value' => 'KENSETSU', 'label' => 'Kensetsu / konstruksi'],
            ['value' => 'NOUGYOU', 'label' => 'Nougyou / pertanian'],
            ['value' => 'SEIZOU', 'label' => 'Seizou / manufaktur'],
            ['value' => 'GAISHOKU', 'label' => 'Gaishoku / layanan makanan'],
            ['value' => 'UMUM', 'label' => 'Umum'],
        ]],
        'phone' => ['control' => 'tel', 'autocomplete' => 'tel'],
    ];

    public function __construct(private CheckoutSessionHttpContract $contract) {}

    /** @param array<string, mixed> $summary
     * @return array{action:string,profile:array<string,array<string,mixed>>,consents:array<string,array{documentVersion:string,documentHash:string}>}|null
     */
    public function present(array $summary): ?array
    {
        if (! $this->contract->enabled() || $this->contract->confirmationJsonBodyLimit() === null
            || config('assessment_integration.checkout_session.http.confirmation.writer_enabled') !== true
            || ($summary['contractVersion'] ?? null) !== 'checkout-summary-v2') {
            return null;
        }
        $consents = $summary['consents'] ?? null;
        if (! is_array($consents) || array_keys($consents) !== ['psychotest', 'dass', 'legalReviewPending']
            || ($consents['legalReviewPending'] ?? null) !== false) {
            return null;
        }
        $presentedConsents = [];
        foreach (['psychotest', 'dass'] as $type) {
            $fact = $consents[$type] ?? null;
            $document = ConsentDocument::for($type);
            if (! is_array($fact) || trim($document->version) === '' || trim($document->title) === ''
                || trim($document->text) === '') {
                return null;
            }
            if (($fact['state'] ?? null) === 'accepted') {
                if (array_keys($fact) !== ['state', 'version'] || ($fact['version'] ?? null) !== $document->version) {
                    return null;
                }

                continue;
            }
            if (array_keys($fact) !== ['state', 'document'] || ($fact['state'] ?? null) !== 'required'
                || ($fact['document'] ?? null) !== $document->toPublicArray()) {
                return null;
            }
            $presentedConsents[$type] = [
                'documentVersion' => $document->version,
                'documentHash' => $document->hash,
            ];
        }

        $profile = $summary['profile'] ?? null;
        if (! is_array($profile) || count($profile) !== count(self::CONTROLS) + 1) {
            return null;
        }
        $seen = [];
        $presentedProfile = [];
        foreach ($profile as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)
                || ! is_string($field['label'] ?? null) || trim($field['label']) === ''
                || ! is_string($field['state'] ?? null) || ! is_bool($field['required'] ?? null)) {
                return null;
            }
            $key = $field['key'];
            if (isset($seen[$key]) || ! in_array($key, self::PROFILE_KEYS, true)
                || ($key === 'email') === $field['required']
                || ! in_array($field['state'], ['missing', 'locked'], true)) {
                return null;
            }
            $seen[$key] = true;
            if ($field['state'] === 'locked') {
                if (! is_string($field['displayValue'] ?? null) || trim($field['displayValue']) === '') {
                    return null;
                }

                continue;
            }
            if ($key !== 'email') {
                $presentedProfile[$key] = self::CONTROLS[$key];
            }
        }
        if (array_keys($seen) !== self::PROFILE_KEYS) {
            return null;
        }

        if ($presentedProfile === [] && $presentedConsents === []) {
            return null;
        }

        return ['action' => '/checkout/confirm', 'profile' => $presentedProfile, 'consents' => $presentedConsents];
    }
}
