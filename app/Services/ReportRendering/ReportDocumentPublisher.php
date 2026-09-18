<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\ReportIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Persists a rendered F6 report PDF on the private `reports` disk and
 * issues a short-lived temporary URL for it. Object keys are random
 * (following the repo's upload convention, e.g. StoreAssessmentBillProof)
 * and never derived from participant data. The URL lifetime is a local
 * constant because report-specific configuration lives outside this
 * package's ownership. Fixture-driven until the F5 review/signature
 * contract is final.
 */
final readonly class ReportDocumentPublisher
{
    public const DISK = 'reports';

    /** @var list<string> */
    public const DOCUMENT_TYPES = ['hpp', 'internal'];

    /**
     * Temporary URL lifetime in minutes. Deliberately short: report
     * documents are confidential and links are meant for one-off pickup,
     * not sharing.
     */
    public const TEMPORARY_URL_MINUTES = 15;

    private const KEY_PATTERN = '/^reports\/(hpp|internal)\/[a-z0-9]{2}\/[a-z0-9]{62}\.pdf$/D';

    /**
     * @return array{object_key: string, url: string, expires_at: string, size: int, checksum: string}
     */
    public function publish(string $documentType, ReportIdentity $identity, string $pdf): array
    {
        if (! in_array($documentType, self::DOCUMENT_TYPES, true)) {
            throw new RuntimeException("Report document type [{$documentType}] is unknown.");
        }

        if ($pdf === '' || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('Report document publisher requires a PDF binary.');
        }

        $key = $this->objectKey($documentType);

        try {
            $stored = Storage::disk(self::DISK)->put($key, $pdf, ['visibility' => 'private']);
        } catch (Throwable) {
            throw new RuntimeException('Report document storage failed.');
        }

        if (! $stored) {
            throw new RuntimeException('Report document storage failed.');
        }

        $expiresAt = CarbonImmutable::now()->addMinutes(self::TEMPORARY_URL_MINUTES);

        try {
            $url = Storage::disk(self::DISK)->temporaryUrl($key, $expiresAt);
        } catch (Throwable) {
            $this->deleteBestEffort($key);

            throw new RuntimeException('Report document temporary URL issuance failed.');
        }

        return [
            'object_key' => $key,
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
            'size' => strlen($pdf),
            'checksum' => hash('sha256', $pdf),
        ];
    }

    /**
     * Random object key, never derived from the report identity: leaks
     * nothing about the participant and is unguessable/unscrapable.
     */
    private function objectKey(string $documentType): string
    {
        $random = Str::lower(Str::random(64));
        $key = "reports/{$documentType}/".substr($random, 0, 2).'/'.substr($random, 2).'.pdf';

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new RuntimeException('Generated report document key is invalid.');
        }

        return $key;
    }

    private function deleteBestEffort(string $key): void
    {
        try {
            Storage::disk(self::DISK)->delete($key);
        } catch (Throwable) {
            // Best effort only: the caller has already received the real error.
        }
    }
}
