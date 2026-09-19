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
 * dataset listing gap codes; nothing is guessed or defaulted.
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

    public const REPORT_NUMBER_UNAVAILABLE = 'REPORT_NUMBER_UNAVAILABLE';

    public const PSYCHOLOGIST_SIPP_UNAVAILABLE = 'PSYCHOLOGIST_SIPP_UNAVAILABLE';

    public const RECOMMENDATION_RATIONALE_UNAVAILABLE = 'RECOMMENDATION_RATIONALE_UNAVAILABLE';

    public const ASPECT_LABELS_UNAVAILABLE = 'ASPECT_LABELS_UNAVAILABLE';

    public const DASS_TEXT_UNAVAILABLE = 'DASS_TEXT_UNAVAILABLE';

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

    public function hpp(string $casePublicId): SignedHppDataset
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
                ->select(['payload', 'checksum'])
                ->where('code', 'ist')
                ->where('version', $istVersion)
                ->first()), $iq)
            : null;
        if ($iqCategory === null) {
            $missing[] = self::IQ_CATEGORY_UNAVAILABLE;
        }

        $dassCategory = $rows['dass_category'];
        if ($dassCategory === null) {
            $missing[] = self::DASS_RESULT_NOT_FOUND;
        } elseif (! in_array($dassCategory, DassScreeningSummary::CATEGORIES, true)) {
            $missing[] = self::DASS_CATEGORY_UNRECOGNIZED;
        }

        $psychologistRow = $rows['psychologist'];
        if ($psychologistRow === null) {
            $missing[] = self::PSYCHOLOGIST_NOT_FOUND;
        }

        $reportNumber = $this->supplemental->reportNumber((int) $snapshotRow->assessment_case_id, $snapshotId);
        if ($reportNumber === null) {
            $missing[] = self::REPORT_NUMBER_UNAVAILABLE;
        }

        $sipp = $psychologistRow === null ? null : $this->supplemental->psychologistSippNumber((int) $psychologistRow->id);
        if ($psychologistRow !== null && $sipp === null) {
            $missing[] = self::PSYCHOLOGIST_SIPP_UNAVAILABLE;
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
            $missing[] = self::DASS_TEXT_UNAVAILABLE;
        }

        if ($missing !== []) {
            return SignedHppDataset::blocked($snapshotId, $missing);
        }

        /** @var stdClass $participant */
        /** @var stdClass $psychologistRow */
        /** @var array<string, array{label_id: string, label_jp: string}> $aspectLabels */
        /** @var array{narrative: string, follow_up: string} $dassText */
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
                DassScreeningSummary::fromArray([
                    'general_category' => $dassCategory,
                    'narrative' => $dassText['narrative'],
                    'follow_up' => $dassText['follow_up'],
                ]),
                self::LABELS[$label],
                (string) $rationale,
                $prerequisite['accompaniment_conditions'] ?? null,
                [
                    'name' => (string) $psychologistRow->name,
                    'sipp_number' => (string) $sipp,
                    'signature_note' => null,
                    'signed_at' => (string) $snapshotRow->signed_at,
                ],
            );
        } catch (InvalidArgumentException) {
            return SignedHppDataset::blocked($snapshotId, [self::DRAFT_INVALID]);
        }

        return SignedHppDataset::ready($snapshotId, $draft, $identity);
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
                ->select(['id', 'name'])
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
     * Same integrity rule as the IST scorer: the payload must match its
     * recorded checksum before its band table is trusted.
     */
    private function iqCategory(?stdClass $row, int $iq): ?string
    {
        if ($row === null || ! is_string($row->payload) || ! is_string($row->checksum)
            || ! hash_equals($row->checksum, hash('sha256', $row->payload))) {
            return null;
        }

        try {
            $data = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
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
