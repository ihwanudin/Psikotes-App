<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Domain\Review\G7AspectResolution;
use App\Domain\Review\G7ReviewSet;
use App\Domain\Review\ProfessionalOverridePolicy;
use App\Domain\Review\ReportSigningPrerequisitePolicy;
use App\Domain\Review\ReportSigningSnapshotComposer;
use App\Domain\Review\ReportSigningTransitionPolicy;
use App\Domain\Review\ReviewedEligibilityDecision;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class ReportSigningService
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    public function __construct(
        private readonly RlsContextRunner $runner,
    ) {}

    /**
     * Execute the full signing chain from stored data — not from client claims.
     *
     * @param  string  $casePublicId  assessment_cases.public_id
     * @param  array<string, mixed>  $input  Already-validated input matching
     *                                       ReportSigningController::sign() validation rules.
     * @return array{success: true, data: array<string, mixed>}
     *                                                          | array{success: false, code: string, message: string, status: int, blocking_reason_codes?: list<string>}
     */
    public function sign(string $casePublicId, Admin $psychologist, array $input): array
    {
        // Step 1: Load eligibility baseline from stored data
        $eligibilityRow = $this->runner->runAsService(function () use ($casePublicId, $input): ?object {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $casePublicId)
                ->first();
            if ($caseRecord === null) {
                return null;
            }

            return DB::table('eligibility_decision_versions')
                ->where('id', $input['eligibility_version_id'])
                ->where('assessment_case_id', (int) $caseRecord->id)
                ->first();
        });

        if ($eligibilityRow === null) {
            return ['success' => false, 'code' => 'ELIGIBILITY_VERSION_NOT_FOUND', 'message' => 'Eligibility version not found for this assessment case.', 'status' => 404];
        }

        // Reconstruct EligibilityDecisionSnapshot from canonical_input_json
        $canonicalInput = json_decode($eligibilityRow->canonical_input_json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($canonicalInput)) {
            return ['success' => false, 'code' => 'INVALID_BASELINE', 'message' => 'Eligibility baseline data is corrupt.', 'status' => 500];
        }

        try {
            $baseline = EligibilityDecisionSnapshot::create($canonicalInput);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'code' => 'INVALID_BASELINE', 'message' => 'Eligibility baseline reconstruction failed: '.$e->getMessage(), 'status' => 500];
        }

        $baselineArray = $baseline->toArray();

        // Step 2: Apply professional overrides via policy (server-side validation)
        $policy = new ProfessionalOverridePolicy;
        $levelOverrides = [];
        foreach ($input['level_overrides'] as $override) {
            try {
                $levelOverrides[] = $policy->levelOverride([
                    'aspect' => $override['aspect'],
                    'system_level' => (int) $override['system_level'],
                    'final_level' => (int) $override['final_level'],
                    'reason' => $override['reason'],
                ]);
            } catch (\InvalidArgumentException $e) {
                return ['success' => false, 'code' => 'OVERRIDE_INVALID', 'message' => 'Level override for '.$override['aspect'].' is invalid: '.$e->getMessage(), 'status' => 422];
            }
        }

        $labelOverride = null;
        if (isset($input['label_override'])) {
            try {
                $labelOverride = $policy->labelOverride([
                    'system_label' => $input['label_override']['system_label'],
                    'final_label' => $input['label_override']['final_label'],
                    'reason' => $input['label_override']['reason'],
                ]);
            } catch (\InvalidArgumentException $e) {
                return ['success' => false, 'code' => 'OVERRIDE_INVALID', 'message' => 'Label override is invalid: '.$e->getMessage(), 'status' => 422];
            }
        }

        // Step 3: Build ReviewedEligibilityDecision (produces system_levels + final_levels)
        try {
            $reviewed = ReviewedEligibilityDecision::create($baseline, $levelOverrides, $labelOverride);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'code' => 'REVIEWED_ELIGIBILITY_INVALID', 'message' => $e->getMessage(), 'status' => 422];
        }

        // Step 4: Build G7 resolutions — validate client-supplied per-source data through policy
        //
        // TODO(G7-data-gap): AspectSourceDiscrepancyPolicy::evaluate() hanya
        // memvalidasi struktur sources dari klien, tidak cross-check ke
        // generic_instrument_result_sources. Perlu aggregator database sebelum
        // sources dianggap otoritatif. Dampak terbatas pada sinyal review_required
        // saja — validity/label/final_level tetap terkunci oleh
        // ReviewedEligibilityDecision.
        $discrepancyPolicy = new AspectSourceDiscrepancyPolicy;
        $g7ByAspect = [];

        foreach ($input['g7_resolutions'] as $resolution) {
            $aspect = $resolution['aspect'];
            if (! in_array($aspect, self::ASPECTS, true)) {
                return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 aspect {$aspect} is not canonical.", 'status' => 422];
            }
            if (isset($g7ByAspect[$aspect])) {
                return ['success' => false, 'code' => 'G7_INVALID', 'message' => "Duplicate G7 resolution for aspect {$aspect}.", 'status' => 422];
            }

            try {
                $discrepancy = $discrepancyPolicy->evaluate([
                    'aspect' => $aspect,
                    'sources' => $resolution['sources'],
                ]);
            } catch (\InvalidArgumentException $e) {
                return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 discrepancy for {$aspect} is invalid: ".$e->getMessage(), 'status' => 422];
            }

            $g7ByAspect[$aspect] = [
                'discrepancy' => $discrepancy,
                'final_level' => isset($resolution['final_level']) ? (int) $resolution['final_level'] : null,
                'reason' => $resolution['reason'] ?? null,
            ];
        }

        // Fill NOT_REQUIRED for aspects the client didn't supply
        $resolutions = [];
        foreach (self::ASPECTS as $aspect) {
            if (isset($g7ByAspect[$aspect])) {
                $entry = $g7ByAspect[$aspect];
                try {
                    if ($entry['discrepancy']['review_required']) {
                        if ($entry['final_level'] === null) {
                            $resolutions[] = G7AspectResolution::unresolved(
                                $entry['discrepancy'], $this->systemLevel($baselineArray, $aspect),
                            );
                        } else {
                            $resolutions[] = G7AspectResolution::resolved(
                                $entry['discrepancy'], $this->systemLevel($baselineArray, $aspect),
                                $entry['final_level'], $entry['reason'],
                            );
                        }
                    } else {
                        if ($entry['final_level'] !== null || $entry['reason'] !== null) {
                            return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 aspect {$aspect} is not review-required but has resolution data.", 'status' => 422];
                        }
                        $resolutions[] = G7AspectResolution::notRequired(
                            $entry['discrepancy'], $this->systemLevel($baselineArray, $aspect),
                        );
                    }
                } catch (\InvalidArgumentException $e) {
                    return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 resolution for {$aspect} is invalid: ".$e->getMessage(), 'status' => 422];
                }
            } else {
                // Not supplied → default to NOT_REQUIRED with single-source discrepancy
                try {
                    $discrepancy = $discrepancyPolicy->evaluate([
                        'aspect' => $aspect,
                        'sources' => [['source' => 'CANONICAL', 'level' => $this->systemLevel($baselineArray, $aspect)]],
                    ]);
                } catch (\InvalidArgumentException $e) {
                    return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 default discrepancy for {$aspect} is invalid.", 'status' => 500];
                }
                if ($discrepancy['review_required']) {
                    return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 aspect {$aspect} requires review but no resolution was supplied.", 'status' => 422];
                }
                try {
                    $resolutions[] = G7AspectResolution::notRequired($discrepancy, $this->systemLevel($baselineArray, $aspect));
                } catch (\InvalidArgumentException $e) {
                    return ['success' => false, 'code' => 'G7_INVALID', 'message' => "G7 default resolution for {$aspect} is invalid.", 'status' => 500];
                }
            }
        }

        // Step 5: Build G7ReviewSet
        try {
            $g7ReviewSet = G7ReviewSet::fromResolutions($resolutions);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'code' => 'G7_INVALID', 'message' => 'G7 review set is invalid: '.$e->getMessage(), 'status' => 422];
        }

        // Step 6: Compose signing snapshot
        $structuralInput = [
            'procedure_note' => $input['procedure_note'],
            'accompaniment_conditions' => $input['accompaniment_conditions'],
            'narrative_clusters' => [
                'A' => $input['narrative_clusters']['A'],
                'B' => $input['narrative_clusters']['B'],
                'C' => $input['narrative_clusters']['C'],
                'D' => $input['narrative_clusters']['D'],
            ],
        ];

        try {
            $snapshot = ReportSigningSnapshotComposer::compose($reviewed, $g7ReviewSet, $structuralInput);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'code' => 'SNAPSHOT_INVALID', 'message' => 'Signing snapshot composition failed: '.$e->getMessage(), 'status' => 422];
        }

        // Step 7: Evaluate prerequisites against DERIVED data (not client input)
        try {
            $prerequisiteResult = (new ReportSigningPrerequisitePolicy)->evaluate(
                $snapshot->prerequisiteInput(),
            );
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'code' => 'PREREQUISITE_INVALID', 'message' => $e->getMessage(), 'status' => 422];
        }

        if (! $prerequisiteResult['can_sign']) {
            return ['success' => false, 'code' => 'SIGNING_BLOCKED', 'message' => 'Report cannot be signed. Resolve the listed blocking conditions.', 'status' => 422, 'blocking_reason_codes' => $prerequisiteResult['blocking_reason_codes']];
        }

        // Step 8: Validate state transition
        try {
            $transitionResult = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $snapshot);
        } catch (\DomainException $e) {
            return ['success' => false, 'code' => 'STATE_INVALID', 'message' => $e->getMessage(), 'status' => 422];
        }

        if (! $transitionResult['can_sign']) {
            return ['success' => false, 'code' => 'SIGNING_BLOCKED', 'message' => 'Report cannot be signed.', 'status' => 422, 'blocking_reason_codes' => $transitionResult['blocking_reason_codes']];
        }

        // Step 9: Persist snapshot. Running the revision-reason check and the
        // version-chain read inside one runAsService closure does NOT by
        // itself close the TOCTOU window - it's one transaction, but an
        // unlocked `SELECT ... ORDER BY version DESC` under READ COMMITTED
        // (Postgres's default) still lets two concurrent signings both read
        // the same `latest` row, so both could pass (or both wrongly skip)
        // the revision_reason check and both try to insert the next version.
        // What actually closes the window is the `lockForUpdate()` below: it
        // serializes concurrent signing attempts on the SAME case (the
        // second request blocks until the first's transaction commits or
        // rolls back), so by the time this closure reads `latest`, no other
        // signing for this case can be mid-flight. The unique constraint on
        // (assessment_case_id, version) is kept as a second, independent
        // safety net - if it's ever hit anyway, the second signer gets a
        // clean 409 SIGNING_CONFLICT instead of an unhandled 500.
        $signedAt = now();
        $signedByAdminId = (int) $psychologist->id;

        $signingClosure = function () use (
            $casePublicId, $input, $signedAt, $signedByAdminId, $snapshot,
        ): array {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $casePublicId)
                ->lockForUpdate()
                ->first();
            if ($caseRecord === null) {
                return ['found' => false, 'error_code' => 'CASE_NOT_FOUND'];
            }
            $caseId = (int) $caseRecord->id;

            $narrative = DB::table('bilingual_narrative_versions')
                ->where('id', $input['narrative_version_id'])
                ->where('assessment_case_id', $caseId)
                ->first();
            if ($narrative === null) {
                return ['found' => false, 'error_code' => 'NARRATIVE_VERSION_NOT_FOUND'];
            }

            // Safe to read as "the" latest now: the case row lock above
            // means no other signing attempt for this case is mid-flight.
            $latest = DB::table('report_signing_snapshots')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            // Validate revision_reason if re-signing over a SIGNED snapshot.
            if ($latest !== null && $latest->state === 'SIGNED') {
                // A report already signed by a DIFFERENT psychologist may not
                // be re-signed here. There is deliberately no case-reassignment
                // path yet - a psychologist who disagrees with a colleague's
                // signed report is a process question for the project owner,
                // not something this endpoint decides by simply overwriting
                // signed_by_admin_id. See handoff doc for the finding this
                // guards against.
                if ((int) $latest->signed_by_admin_id !== $signedByAdminId) {
                    return ['found' => false, 'error_code' => 'SIGNED_BY_ANOTHER_PSYCHOLOGIST'];
                }
                $reason = isset($input['revision_reason']) && is_string($input['revision_reason']) ? trim($input['revision_reason']) : '';
                if (mb_strlen($reason) < 20) {
                    return ['found' => false, 'error_code' => 'REVISION_REASON_REQUIRED'];
                }
                $revisionReason = $reason;
                $supersededVersion = (int) $latest->version;
            } else {
                $revisionReason = null;
                $supersededVersion = null;
            }

            $snapshotJson = json_encode([
                'prerequisite_input' => $snapshot->prerequisiteInput(),
                'provenance' => $snapshot->provenance(),
            ] + ($revisionReason !== null ? ['revision' => [
                'reason' => $revisionReason,
                'supersedes_version' => $supersededVersion,
            ]] : []), JSON_THROW_ON_ERROR);

            $version = $latest === null ? 1 : $latest->version + 1;
            $supersedesId = $latest?->id;
            $id = (string) Str::ulid();

            // insertOrIgnore(), not insert(): on PostgreSQL this compiles to
            // INSERT ... ON CONFLICT DO NOTHING (SQLite: INSERT OR IGNORE),
            // so a unique violation on either (assessment_case_id, version)
            // or supersedes_id - both mean a concurrent signer already won -
            // silently inserts zero rows instead of throwing. That matters
            // specifically here: a thrown QueryException would abort the
            // WHOLE Postgres transaction (25P02) until rolled back, and
            // runAsService()'s own cleanup (restoring the previous RLS role
            // in its finally block) would then itself fail on the poisoned
            // connection before this method ever got a chance to handle it
            // - confirmed by actually triggering that, not assumed; see
            // tasks/handoffs/f5/report-signing-conflict-500.md. Not throwing
            // at all sidesteps that entirely, with no need to touch
            // RlsContextRunner.
            $inserted = DB::table('report_signing_snapshots')->insertOrIgnore([
                'id' => $id,
                'assessment_case_id' => $caseId,
                'version' => $version,
                'supersedes_id' => $supersedesId,
                'state' => 'SIGNED',
                'eligibility_version_id' => $input['eligibility_version_id'],
                'narrative_version_id' => $input['narrative_version_id'],
                'snapshot_json' => $snapshotJson,
                'signed_by_admin_id' => $signedByAdminId,
                'signed_at' => $signedAt,
                'created_at' => $signedAt,
            ]);

            if ($inserted === 0) {
                // Should not happen with the row lock above in place - kept
                // as a second, independent safety net for if it's ever
                // bypassed.
                return ['found' => false, 'error_code' => 'SIGNING_CONFLICT'];
            }

            return ['found' => true, 'row' => DB::table('report_signing_snapshots')->where('id', $id)->sole()];
        };

        $result = $this->runner->runAsService($signingClosure);

        if (! $result['found']) {
            $messages = [
                'CASE_NOT_FOUND' => 'Assessment case not found.',
                'NARRATIVE_VERSION_NOT_FOUND' => 'Referenced version does not belong to this assessment case.',
                'REVISION_REASON_REQUIRED' => 'Revisi memerlukan alasan minimal 20 karakter.',
                'SIGNING_CONFLICT' => 'A conflicting signing attempt for this case was just committed. Reload and try again.',
                'SIGNED_BY_ANOTHER_PSYCHOLOGIST' => 'Laporan ini sudah ditandatangani oleh psikolog lain. Hanya psikolog yang menandatangani versi sebelumnya yang dapat mengajukan revisi.',
            ];
            $statuses = [
                'CASE_NOT_FOUND' => 404,
                'NARRATIVE_VERSION_NOT_FOUND' => 404,
                'REVISION_REASON_REQUIRED' => 422,
                'SIGNING_CONFLICT' => 409,
                'SIGNED_BY_ANOTHER_PSYCHOLOGIST' => 403,
            ];

            return ['success' => false, 'code' => $result['error_code'], 'message' => $messages[$result['error_code']], 'status' => $statuses[$result['error_code']]];
        }

        $row = $result['row'];

        return ['success' => true, 'data' => [
            'id' => $row->id,
            'assessmentCaseId' => (int) $row->assessment_case_id,
            'version' => (int) $row->version,
            'state' => $row->state,
            'eligibilityVersionId' => $row->eligibility_version_id,
            'narrativeVersionId' => $row->narrative_version_id,
            'snapshot' => json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
            'signedByAdminId' => (int) $row->signed_by_admin_id,
            'signedAt' => $row->signed_at,
            'createdAt' => $row->created_at,
        ]];
    }

    /**
     * Query the latest signing snapshot for a case.
     *
     * @return stdClass|null The latest snapshot row, or null if none exists.
     */
    public function latest(string $casePublicId): ?stdClass
    {
        return $this->runner->runAsService(function () use ($casePublicId): ?stdClass {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $casePublicId)
                ->first();
            if ($caseRecord === null) {
                return null;
            }

            /** @var stdClass|null */
            return DB::table('report_signing_snapshots')
                ->where('assessment_case_id', (int) $caseRecord->id)
                ->orderByDesc('version')
                ->first();
        });
    }

    /**
     * @param  array<string, mixed>  $baselineArray
     */
    private function systemLevel(array $baselineArray, string $aspect): int
    {
        return (int) $baselineArray['zone']['aspects'][$aspect]['level'];
    }
}
