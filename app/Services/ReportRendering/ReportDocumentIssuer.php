<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/**
 * Orchestrates report_documents: the "is there already a usable PDF for
 * this snapshot" decision that ReportDocumentPublisher deliberately does
 * not make on its own (tasks/handoffs/f6/report-documents-schema-proposal.md
 * §4/§9). Idempotent: given a snapshot+document type that already has a
 * row whose object still exists in storage, reuses it (no render call,
 * no new row) and only issues a fresh link. Renders and stores a new
 * object — bumping render_seq — only when nothing reusable exists.
 *
 * Not wired into ReportGeneration yet: doing so needs a design decision
 * (when a report number is first assigned for a case that has never had
 * a document, given HppReportDraft/ReportIdentity are built before this
 * class runs) that is out of this increment's scope and is flagged
 * separately to the coordinator.
 */
final readonly class ReportDocumentIssuer
{
    public function __construct(
        private RlsContextRunner $runner,
        private ReportDocumentPublisher $publisher,
        private ReportNumberIssuer $numberIssuer,
    ) {}

    /**
     * @param  callable(string $reportNumber): string  $renderPdf  Lazy: receives the
     *                                                             resolved report number and returns PDF bytes. Called at most
     *                                                             once, and only when no reusable render exists.
     * @return array{report_number: string, object_key: string, url: string, expires_at: string, reused: bool}
     */
    public function issue(
        int $assessmentCaseId,
        string $signingSnapshotId,
        string $documentType,
        int $reportVersion,
        int $psychologistAdminId,
        string $psychologistName,
        string $psychologistSilp,
        string $psychologistStr,
        string $facilityName,
        callable $renderPdf,
    ): array {
        if (! in_array($documentType, ReportDocumentPublisher::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException("Report document type [{$documentType}] is unknown.");
        }

        return $this->runner->runAsService(function () use (
            $assessmentCaseId, $signingSnapshotId, $documentType, $reportVersion,
            $psychologistAdminId, $psychologistName, $psychologistSilp, $psychologistStr,
            $facilityName, $renderPdf,
        ): array {
            // Serializes concurrent issue() calls for the same case, so two
            // simultaneous first-time generations (e.g. hpp + internal)
            // cannot each allocate a different report number.
            DB::table('assessment_cases')->where('id', $assessmentCaseId)->lockForUpdate()->value('id');

            $existing = DB::table('report_documents')
                ->where('signing_snapshot_id', $signingSnapshotId)
                ->where('document_type', $documentType)
                ->orderByDesc('render_seq')
                ->first();

            if ($existing !== null && Storage::disk(ReportDocumentPublisher::DISK)->exists($existing->object_key)) {
                $link = $this->publisher->issueLink($existing->object_key);

                return [
                    'report_number' => $existing->report_number,
                    'object_key' => $existing->object_key,
                    'url' => $link['url'],
                    'expires_at' => $link['expires_at'],
                    'reused' => true,
                ];
            }

            $reportNumber = $existing->report_number ?? $this->priorReportNumber($assessmentCaseId) ?? $this->numberIssuer->issue();

            $pdf = $renderPdf($reportNumber);
            $stored = $this->publisher->storeObject($documentType, $pdf);

            $renderSeq = ((int) ($existing->render_seq ?? 0)) + 1;
            $now = CarbonImmutable::now();

            DB::table('report_documents')->insert([
                'id' => (string) Str::ulid(),
                'assessment_case_id' => $assessmentCaseId,
                'signing_snapshot_id' => $signingSnapshotId,
                'document_type' => $documentType,
                'report_number' => $reportNumber,
                'report_version' => $reportVersion,
                'render_seq' => $renderSeq,
                'object_key' => $stored['object_key'],
                'sha256' => $stored['checksum'],
                'size_bytes' => $stored['size'],
                'psychologist_admin_id' => $psychologistAdminId,
                'psychologist_name_snapshot' => $psychologistName,
                'psychologist_silp_snapshot' => $psychologistSilp,
                'psychologist_str_snapshot' => $psychologistStr,
                'facility_name_snapshot' => $facilityName,
                'generated_at' => $now,
                'created_at' => $now,
            ]);

            $link = $this->publisher->issueLink($stored['object_key']);

            return [
                'report_number' => $reportNumber,
                'object_key' => $stored['object_key'],
                'url' => $link['url'],
                'expires_at' => $link['expires_at'],
                'reused' => false,
            ];
        });
    }

    /**
     * A case's report number is shared across both document types and
     * every re-sign (report-documents-schema-proposal.md §3): once any
     * document has ever been issued for this case, later snapshots reuse
     * that same number rather than drawing a new one.
     */
    private function priorReportNumber(int $assessmentCaseId): ?string
    {
        /** @var stdClass|null $row */
        $row = DB::table('report_documents')
            ->where('assessment_case_id', $assessmentCaseId)
            ->orderByDesc('id')
            ->first(['report_number']);

        return $row?->report_number;
    }
}
