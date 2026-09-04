<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use App\Enums\PayerType;
use App\Registration\ConsentDocument;
use DomainException;
use JsonSerializable;

/** Internal read-only presentation contract; not HTTP/DRAFT compatibility or mutation authority. */
final readonly class CheckoutSummary implements JsonSerializable
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, bool> $readyByType */
    public function __construct(
        CheckoutProfile $profile,
        CheckoutProductPaymentFacts $product,
        string $branchName,
        ConsentDocument $psychotest,
        ?ConsentDocument $dass,
        bool $psychotestAccepted,
        bool $dassAccepted,
        bool $legalReviewPending,
        array $readyByType,
    ) {
        if (trim($branchName) === '' || array_keys($readyByType) !== $product->testTypes
            || $psychotest->type !== 'psychotest'
            || ($dass !== null) !== in_array('dass21', $product->testTypes, true)
            || ($dass !== null && $dass->type !== 'dass')) {
            throw new DomainException('CHECKOUT_SUMMARY_UNAVAILABLE');
        }
        $tests = [];
        $readyCount = 0;
        foreach ($readyByType as $type => $ready) {
            $readyCount += $ready ? 1 : 0;
            $tests[] = ['testType' => $type, 'state' => $ready ? 'ready' : 'locked'];
        }
        $state = $readyCount === count($tests) ? 'ready' : ($readyCount === 0 ? 'locked' : 'partial');
        $payment = $product->payment->toArray()['payment'];
        if ($product->payment->payer === PayerType::Organization) {
            $payment['organizationName'] = $branchName;
        }
        $this->data = [
            'contractVersion' => 'checkout-summary-v1',
            'sourceName' => 'Integrasi seleksi',
            'branchName' => $branchName,
            'packageName' => $product->packageLabel,
            'packageSource' => $product->source,
            'attemptLabel' => 'Assessment Anda',
            ...$profile->toArray(),
            'identityMessage' => 'Kelengkapan profil tidak menggantikan verifikasi identitas.',
            'payment' => $payment,
            'access' => ['state' => $state, 'tests' => $tests, 'startAvailable' => false, 'message' => match ($state) {
                'ready' => 'Prasyarat akses tes terpenuhi; mesin sesi belum tersedia.',
                'partial' => 'Sebagian akses tes belum siap.',
                default => 'Akses tes belum siap.',
            }],
            'consents' => ['psychotest' => $this->consent($psychotest, $psychotestAccepted),
                'dass' => $dass === null ? ['state' => 'not_applicable'] : $this->consent($dass, $dassAccepted),
                'legalReviewPending' => $legalReviewPending],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @return array<string, mixed> */
    private function consent(ConsentDocument $document, bool $accepted): array
    {
        return $accepted ? ['state' => 'accepted', 'version' => $document->version]
            : ['state' => 'required', 'document' => $document->toPublicArray()];
    }
}
