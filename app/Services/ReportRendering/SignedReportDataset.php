<?php

declare(strict_types=1);

namespace App\Services\ReportRendering;

use App\Domain\Report\DassScreeningSummary;
use App\Domain\Report\HppReportDraft;
use App\Domain\Report\ReportAspectGrid;
use App\Domain\Report\ReportIdentity;
use App\Security\RlsContextRunner;
use App\Services\Scoring\IstIqLevelCalculator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Real HPP data source, replacing FixtureReportDataset for production use.
 * Reads the latest SIGNED report_signing_snapshots row for a case, joins
 * participant/branch identity, derives the IQ category from the IST
 * instrument version recorded in eligibility provenance, and reads ONLY
 * the DASS general category (never subscale scores) from the latest
 * completed DASS assessment at or before signing.
 *
 * Fails closed: any missing or inconsistent input yields a blocked
 * dataset listing gap codes; nothing is guessed or defaulted. The single
 * exception is the DASS screening, which is reported as a non-blocking
 * warning so a missing screening never holds up publication.
 */
final readonly class SignedReportDataset
{
    public const SNAPSHOT_NOT_FOUND = 'SNAPSHOT_NOT_FOUND';

    public const SNAPSHOT_NOT_SIGNED = 'SNAPSHOT_NOT_SIGNED';

    public const SNAPSHOT_CORRUPT = 'SNAPSHOT_CORRUPT';

    public const PUBLICATION_BLOCKED = 'PUBLICATION_BLOCKED';

    public const PARTICIPANT_NOT_FOUND = 'PARTICIPANT_NOT_FOUND';

    public const TEST_NUMBER_MISSING = 'TEST_NUMBER_MISSING';

    public const TEST_DATE_UNAVAILABLE = 'TEST_DATE_UNAVAILABLE';

    public const NARRATIVE_CLUSTER_MISSING = 'NARRATIVE_CLUSTER_MISSING';

    public const IQ_CATEGORY_UNAVAILABLE = 'IQ_CATEGORY_UNAVAILABLE';

    public const DASS_RESULT_NOT_FOUND = 'DASS_RESULT_NOT_FOUND';

    public const DASS_CATEGORY_UNRECOGNIZED = 'DASS_CATEGORY_UNRECOGNIZED';

    public const PSYCHOLOGIST_NOT_FOUND = 'PSYCHOLOGIST_NOT_FOUND';

    /**
     * Non-blocking: no document has ever been issued for this case yet.
     * Reported as a warning (not `missing`) so the very first generation
     * for a case is never blocked on having a number it is about to get.
     */
    public const REPORT_NUMBER_NOT_YET_ISSUED = 'REPORT_NUMBER_NOT_YET_ISSUED';

    /**
     * Blocking, never a blank print: Template HPP v2.3 Bagian I.C requires
     * both SILP and STR on the printed report (source-authority-inventory.md
     * :72-73). Read from real admins.silp_number/str_number columns.
     */
    public const PSYCHOLOGIST_SILP_MISSING = 'PSYCHOLOGIST_SILP_MISSING';

    public const PSYCHOLOGIST_STR_MISSING = 'PSYCHOLOGIST_STR_MISSING';

    public const RECOMMENDATION_RATIONALE_UNAVAILABLE = 'RECOMMENDATION_RATIONALE_UNAVAILABLE';

    public const ASPECT_LABELS_UNAVAILABLE = 'ASPECT_LABELS_UNAVAILABLE';

    public const DASS_TEXT_UNAVAILABLE = 'DASS_TEXT_UNAVAILABLE';

    /**
     * Blocking: Template HPP v2.3 Bagian I.C also requires the publisher's
     * facility name/address on every report (config/report.php). In
     * practice this should never be empty — it's a fixed literal, not
     * per-environment — but a report is never allowed to print a blank
     * facility identity, so it still fails closed rather than assume.
     */
    public const FACILITY_NAME_MISSING = 'FACILITY_NAME_MISSING';

    public const FACILITY_ADDRESS_MISSING = 'FACILITY_ADDRESS_MISSING';

    public const DRAFT_INVALID = 'DRAFT_INVALID';

    /** Signing/eligibility label codes mapped to the printed HPP labels. */
    private const LABELS = [
        'DISARANKAN' => 'DISARANKAN',
        'DIPERTIMBANGKAN' => 'DIPERTIMBANGKAN',
        'TIDAK_DISARANKAN' => 'TIDAK DISARANKAN',
    ];

    private ReportSupplementalData $supplemental;

    public function __construct(
        private RlsContextRunner $runner,
        ?ReportSupplementalData $supplemental = null,
    ) {
        $this->supplemental = $supplemental ?? new UnavailableReportSupplementalData;
    }

    /**
     * @param  ?string  $reportNumberOverride  Used only by ReportDocumentIssuer's render
     *                                         callback, after it has resolved a real number: forces that number in
     *                                         and skips the read-only lookup, guaranteeing a materialized draft.
     *                                         Without it, a case with no number yet is still `ready` (ineligible
     *                                         for a blocked state) but reports REPORT_NUMBER_NOT_YET_ISSUED as a
     *                                         warning and leaves draft()/identity() unmaterialized.
     */
    public function hpp(string $casePublicId, ?string $reportNumberOverride = null): SignedHppDataset
    {
        $rows = $this->runner->runAsService(fn (): ?array => $this->loadRows($casePublicId));

        if ($rows === null || $rows['snapshot'] === null) {
            return SignedHppDataset::blocked(null, [self::SNAPSHOT_NOT_FOUND]);
        }

        /** @var stdClass $snapshotRow */
        $snapshotRow = $rows['snapshot'];
        $snapshotId = (string) $snapshotRow->id;

        if ($snapshotRow->state !== 'SIGNED') {
            return SignedHppDataset::blocked($snapshotId, [self::SNAPSHOT_NOT_SIGNED]);
        }

        $snapshot = $this->decodeSnapshot((string) $snapshotRow->snapshot_json);
        if ($snapshot === null) {
            return SignedHppDataset::blocked($snapshotId, [self::SNAPSHOT_CORRUPT]);
        }

        [$prerequisite, $reviewed] = $snapshot;
        $missing = [];

        $label = $prerequisite['label'] ?? null;
        if (($reviewed['publication_blocked'] ?? true) !== false || ! is_string($label) || ! isset(self::LABELS[$label])) {
            return SignedHppDataset::blocked($snapshotId, [self::PUBLICATION_BLOCKED]);
        }

        $zone = $reviewed['recalculated_decision']['zone'];
        $standardVersion = (string) $zone['standard_version'];
        $iq = $reviewed['recalculated_decision']['recommendation']['provenance']['iq'];
        $istVersion = $reviewed['system_decision']['provenance']['eligibility_source_versions']['ist'] ?? null;

        $participant = $rows['participant'];
        if ($participant === null) {
            $missing[] = self::PARTICIPANT_NOT_FOUND;
        } elseif (! is_string($participant->test_number) || trim($participant->test_number) === '') {
            $missing[] = self::TEST_NUMBER_MISSING;
        }

        if ($rows['test_date'] === null) {
            $missing[] = self::TEST_DATE_UNAVAILABLE;
        }

        $clusters = [];
        foreach (['A', 'B', 'C', 'D'] as $cluster) {
            $text = $prerequisite['narrative_clusters'][$cluster] ?? null;
            if (! is_string($text) || trim($text) === '') {
                $missing[] = self::NARRATIVE_CLUSTER_MISSING;

                continue;
            }
            $clusters[$cluster] = $text;
        }

        $iqCategory = is_int($iq) && is_string($istVersion)
            ? $this->iqCategory($this->runner->runAsService(static fn (): ?stdClass => DB::table('instrument_versions')
                ->select(['source_text', 'checksum'])
                ->where('code', 'ist')
                ->where('version', $istVersion)
                ->first()), $iq)
            : null;
        if ($iqCategory === null) {
            $missing[] = self::IQ_CATEGORY_UNAVAILABLE;
        }

        // DASS never blocks publication (owner decision 2026-09-20): a missing
        // or unusable screening is reported as a warning and the HPP prints
        // "tidak tersedia". G4/T-07 is unaffected: DASS still never reaches
        // any zone/label expression.
        $warnings = [];
        $dassCategory = $rows['dass_category'];
        if ($dassCategory === null) {
            $warnings[] = self::DASS_RESULT_NOT_FOUND;
            $dassCategory = null;
        } elseif (! in_array($dassCategory, DassScreeningSummary::CATEGORIES, true)) {
            $warnings[] = self::DASS_CATEGORY_UNRECOGNIZED;
            $dassCategory = null;
        }

        $psychologistRow = $rows['psychologist'];
        $silp = null;
        $str = null;
        if ($psychologistRow === null) {
            $missing[] = self::PSYCHOLOGIST_NOT_FOUND;
        } else {
            $silp = is_string($psychologistRow->silp_number) && trim($psychologistRow->silp_number) !== ''
                ? $psychologistRow->silp_number : null;
            if ($silp === null) {
                $missing[] = self::PSYCHOLOGIST_SILP_MISSING;
            }
            $str = is_string($psychologistRow->str_number) && trim($psychologistRow->str_number) !== ''
                ? $psychologistRow->str_number : null;
            if ($str === null) {
                $missing[] = self::PSYCHOLOGIST_STR_MISSING;
            }
        }

        $reportNumber = $reportNumberOverride
            ?? $this->supplemental->reportNumber((int) $snapshotRow->assessment_case_id, $snapshotId);
        if ($reportNumber === null) {
            $warnings[] = self::REPORT_NUMBER_NOT_YET_ISSUED;
        }

        // Fixed publisher identity (config/report.php), not per-branch: a
        // report never prints a blank facility name/address, even though
        // in practice this config should never actually be empty.
        $facilityName = trim((string) config('report.facility_name'));
        if ($facilityName === '') {
            $missing[] = self::FACILITY_NAME_MISSING;
        }
        $facilityAddress = trim((string) config('report.facility_address'));
        if ($facilityAddress === '') {
            $missing[] = self::FACILITY_ADDRESS_MISSING;
        }

        $rationale = $this->supplemental->recommendationRationale($snapshotId);
        if ($rationale === null) {
            $missing[] = self::RECOMMENDATION_RATIONALE_UNAVAILABLE;
        }

        $aspectLabels = $this->supplemental->aspectLabels($standardVersion);
        if ($aspectLabels === null) {
            $missing[] = self::ASPECT_LABELS_UNAVAILABLE;
        }

        $dassText = is_string($dassCategory) ? $this->supplemental->dassScreeningText($dassCategory) : null;
        if (is_string($dassCategory) && $dassText === null) {
            $warnings[] = self::DASS_TEXT_UNAVAILABLE;
            $dassCategory = null;
        }

        if ($missing !== []) {
            return SignedHppDataset::blocked($snapshotId, $missing, $warnings);
        }

        /** @var stdClass $participant */
        /** @var stdClass $psychologistRow */
        /** @var array<string, array{label_id: string, label_jp: string}> $aspectLabels */
        $issuance = [
            'case_id' => (int) $snapshotRow->assessment_case_id,
            'snapshot_version' => (int) $snapshotRow->version,
            'psychologist_admin_id' => (int) $psychologistRow->id,
            'psychologist_name' => (string) $psychologistRow->name,
            'psychologist_silp' => (string) $silp,
            'psychologist_str' => (string) $str,
            // The publisher's own facility identity (config/report.php),
            // never the participant's assessment branch (report-documents
            // -schema-proposal.md §6) — PR #39 read this from branch_name,
            // a defect caught before any real report was ever issued.
            'facility_name' => $facilityName,
        ];

        if ($reportNumber === null) {
            // Everything else checks out; only the number is missing, and
            // that is a warning, not a gap. Nothing to render yet — the
            // issuer resolves a number and calls hpp() again with it.
            return SignedHppDataset::ready($snapshotId, $issuance, null, null, $warnings);
        }

        try {
            $identity = ReportIdentity::fromArray([
                'report_number' => (string) $reportNumber,
                'participant_name' => (string) $participant->full_name,
                'test_number' => (string) $participant->test_number,
                'birth_date' => substr((string) $participant->birth_date, 0, 10),
                'education' => (string) $participant->education_level,
                'branch_name' => (string) $rows['branch_name'],
                'target_field' => (string) $prerequisite['target_field'],
                'standard_version' => $standardVersion,
                'test_date' => (string) $rows['test_date'],
            ]);

            $draft = HppReportDraft::create(
                $identity,
                $this->aspectGrid($reviewed, $aspectLabels),
                (int) $iq,
                (string) $iqCategory,
                $clusters,
                $dassCategory === null ? null : DassScreeningSummary::fromArray([
                    'general_category' => $dassCategory,
                    'narrative' => $dassText['narrative'],
                    'follow_up' => $dassText['follow_up'],
                ]),
                self::LABELS[$label],
                (string) $rationale,
                $prerequisite['accompaniment_conditions'] ?? null,
                [
                    'name' => (string) $psychologistRow->name,
                    'silp_number' => (string) $silp,
                    'str_number' => (string) $str,
                    'facility_name' => $facilityName,
                    'facility_address' => $facilityAddress,
                    'signature_note' => null,
                    'signed_at' => (string) $snapshotRow->signed_at,
                ],
            );
        } catch (InvalidArgumentException) {
            return SignedHppDataset::blocked($snapshotId, [self::DRAFT_INVALID], $warnings);
        }

        return SignedHppDataset::ready($snapshotId, $issuance, $draft, $identity, $warnings);
    }

    /**
     * @return array{
     *     snapshot: stdClass|null,
     *     participant: stdClass|null,
     *     branch_name: string|null,
     *     test_date: string|null,
     *     dass_category: string|null,
     *     psychologist: stdClass|null
     * }|null
     */
    private function loadRows(string $casePublicId): ?array
    {
        $case = DB::table('assessment_cases')->where('public_id', $casePublicId)->first();
        if ($case === null) {
            return null;
        }
        $caseId = (int) $case->id;

        $snapshot = DB::table('report_signing_snapshots')
            ->where('assessment_case_id', $caseId)
            ->orderByDesc('version')
            ->first();

        $participant = DB::table('participants')
            ->select(['id', 'full_name', 'test_number', 'birth_date', 'education_level'])
            ->where('id', (int) $case->participant_id)
            ->first();

        $branchName = DB::table('branches')->where('id', (int) $case->organization_id)->value('name');

        $submittedAt = DB::table('test_sessions')
            ->where('assessment_case_id', $caseId)
            ->whereNotNull('submitted_at')
            ->whereNull('voided_at')
            ->max('submitted_at');

        $dassCategory = null;
        $psychologist = null;

        if ($snapshot !== null) {
            $dassCategory = $this->dassGeneralCategory((int) $case->participant_id, $snapshot->signed_at);
            $psychologist = $snapshot->signed_by_admin_id === null ? null : DB::table('admins')
                ->select(['id', 'name', 'silp_number', 'str_number'])
                ->where('id', (int) $snapshot->signed_by_admin_id)
                ->first();
        }

        return [
            'snapshot' => $snapshot,
            'participant' => $participant,
            'branch_name' => is_string($branchName) ? $branchName : null,
            'test_date' => is_string($submittedAt) ? substr($submittedAt, 0, 10) : null,
            'dass_category' => $dassCategory,
            'psychologist' => $psychologist,
        ];
    }

    /**
     * Latest completed DASS assessment for the participant at or before the
     * signing moment. Selects ONLY overall_category: subscale raw scores and
     * categories are internal-only and never leave the dass schema here.
     */
    private function dassGeneralCategory(int $participantId, mixed $signedAt): ?string
    {
        if ($signedAt === null) {
            return null;
        }

        $pgsql = DB::getDriverName() === 'pgsql';
        $assessments = $pgsql ? 'dass.assessments' : 'dass_assessments';
        $results = $pgsql ? 'dass.results' : 'dass_results';

        $category = DB::table($assessments)
            ->join($results, "{$results}.assessment_id", '=', "{$assessments}.id")
            ->where("{$assessments}.participant_id", $participantId)
            ->where("{$assessments}.status", 'completed')
            ->whereNotNull("{$assessments}.completed_at")
            ->where("{$assessments}.completed_at", '<=', $signedAt)
            ->orderByDesc("{$assessments}.completed_at")
            ->orderByDesc("{$assessments}.id")
            ->value("{$results}.overall_category");

        return is_string($category) ? $category : null;
    }

    /**
     * Same integrity rule as the IST scorer (ScoreSealedIstAnswerSet),
     * and the same fix for the same twin defect: `payload` is jsonb,
     * which PostgreSQL normalizes on write, so it can never be hashed
     * back to `checksum`. `source_text` is the byte-identical raw text
     * the checksum was actually computed from and is what gets verified
     * here — never `payload`, with no fallback. A NULL `source_text`
     * (nullable: historical rows whose on-disk source can't be honestly
     * reconstructed are left incomplete rather than faked) fails closed
     * the same as a checksum mismatch.
     */
    private function iqCategory(?stdClass $row, int $iq): ?string
    {
        if ($row === null || ! is_string($row->source_text) || ! is_string($row->checksum)
            || ! hash_equals($row->checksum, hash('sha256', $row->source_text))) {
            return null;
        }

        try {
            $data = json_decode($row->source_text, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! is_array($data['iq_level_bands'] ?? null)) {
                return null;
            }

            return (new IstIqLevelCalculator($data['iq_level_bands']))->calculate($iq)['category'];
        } catch (JsonException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function decodeSnapshot(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $prerequisite = $decoded['prerequisite_input'] ?? null;
        $reviewed = $decoded['provenance']['reviewed_eligibility'] ?? null;
        if (! is_array($prerequisite) || ! is_array($reviewed)
            || ! is_array($reviewed['final_levels'] ?? null)
            || ! is_array($reviewed['recalculated_decision']['zone']['aspects'] ?? null)
            || ! is_string($reviewed['recalculated_decision']['zone']['standard_version'] ?? null)
            || ! array_key_exists('iq', $reviewed['recalculated_decision']['recommendation']['provenance'] ?? [])
            || ! is_string($prerequisite['target_field'] ?? null)) {
            return null;
        }

        foreach (ReportAspectGrid::ASPECTS as $aspect) {
            $final = $reviewed['final_levels'][$aspect] ?? null;
            $zoneAspect = $reviewed['recalculated_decision']['zone']['aspects'][$aspect] ?? null;
            if (! is_int($final) || ! is_array($zoneAspect) || ($zoneAspect['level'] ?? null) !== $final) {
                return null;
            }
        }

        return [$prerequisite, $reviewed];
    }

    /**
     * Final (post-override) levels with the field standards the signed
     * decision was recalculated against.
     *
     * @param  array<string, mixed>  $reviewed
     * @param  array<string, array{label_id: string, label_jp: string}>  $labels
     */
    private function aspectGrid(array $reviewed, array $labels): ReportAspectGrid
    {
        $rows = [];
        foreach (ReportAspectGrid::ASPECTS as $aspect) {
            if (! isset($labels[$aspect]['label_id'], $labels[$aspect]['label_jp'])) {
                throw new InvalidArgumentException("Aspect label for {$aspect} is missing.");
            }
            $standard = $reviewed['recalculated_decision']['zone']['aspects'][$aspect]['standard'];
            $rows[] = [
                'code' => $aspect,
                'label_id' => $labels[$aspect]['label_id'],
                'label_jp' => $labels[$aspect]['label_jp'],
                'level' => (int) $reviewed['final_levels'][$aspect],
                'standard' => $standard === null ? null : (int) $standard,
            ];
        }

        return ReportAspectGrid::fromArray($rows);
    }
}
