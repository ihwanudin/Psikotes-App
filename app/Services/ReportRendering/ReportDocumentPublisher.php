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
 * Storage-layer operations for F6 report PDFs on the private `reports`
 * disk. Object keys are random (following the repo's upload convention,
 * e.g. StoreAssessmentBillProof) and never derived from participant data.
 *
 * Split into two independent responsibilities (Lead's 2026-09-20 review,
 * tasks/handoffs/f6/report-documents-schema-proposal.md §4/§9):
 * storeObject() always writes a NEW object under a fresh key — it never
 * decides to reuse one, since "is there already a usable object for this
 * snapshot" is an orchestration decision (ReportDocumentIssuer), not a
 * storage concern. issueLink() is a pure read: given a key that already
 * exists, it signs a fresh temporary URL without touching storage state.
 * publish() is kept as a thin composition of the two for the current
 * single-shot ReportGeneration flow.
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
     * Stores a PDF binary under a brand-new random key. Always a write;
     * never checks for or reuses an existing object.
     *
     * @return array{object_key: string, size: int, checksum: string}
     */
    public function storeObject(string $documentType, string $pdf): array
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

        return [
            'object_key' => $key,
            'size' => strlen($pdf),
            'checksum' => hash('sha256', $pdf),
        ];
    }

    /**
     * Issues a fresh short-lived signed URL for an object that already
     * exists in storage. Pure read: never writes, never deletes.
     *
     * @return array{url: string, expires_at: string}
     */
    public function issueLink(string $objectKey): array
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(self::TEMPORARY_URL_MINUTES);

        try {
            $url = Storage::disk(self::DISK)->temporaryUrl($objectKey, $expiresAt);
        } catch (Throwable) {
            throw new RuntimeException('Report document temporary URL issuance failed.');
        }

        return [
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Convenience composition of storeObject() + issueLink() for a
     * single-shot "render once, hand back a link" flow. $identity is
     * accepted for the caller's documentation/future use; the object key
     * itself is never derived from it (see objectKey()).
     *
     * @return array{object_key: string, url: string, expires_at: string, size: int, checksum: string}
     */
    public function publish(string $documentType, ReportIdentity $identity, string $pdf): array
    {
        $stored = $this->storeObject($documentType, $pdf);

        try {
            $link = $this->issueLink($stored['object_key']);
        } catch (Throwable $e) {
            $this->deleteBestEffort($stored['object_key']);

            throw $e;
        }

        return [
            'object_key' => $stored['object_key'],
            'url' => $link['url'],
            'expires_at' => $link['expires_at'],
            'size' => $stored['size'],
            'checksum' => $stored['checksum'],
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
