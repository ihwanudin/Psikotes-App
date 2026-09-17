<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
use App\Enums\AdminAbility;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReportSigningController extends Controller
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    public function sign(Request $request, string $case, RlsContextRunner $runner): JsonResponse
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ReviewReports)) {
            return $this->error('FORBIDDEN', 'Only psychologists may sign reports.', 403);
        }

        $input = $request->validate([
            'eligibility_version_id' => ['required', 'string'],
            'narrative_version_id' => ['required', 'string'],
            'level_overrides' => ['present', 'array'],
            'level_overrides.*.aspect' => ['required', 'string'],
            'level_overrides.*.system_level' => ['required', 'integer', 'min:1', 'max:5'],
            'level_overrides.*.final_level' => ['required', 'integer', 'min:1', 'max:5'],
            'level_overrides.*.reason' => ['nullable', 'string'],
            'label_override' => ['nullable', 'array'],
            'label_override.system_label' => ['required_with:label_override', 'string'],
            'label_override.final_label' => ['required_with:label_override', 'string'],
            'label_override.reason' => ['nullable', 'string'],
            'g7_resolutions' => ['present', 'array'],
            'g7_resolutions.*.aspect' => ['required', 'string'],
            'g7_resolutions.*.sources' => ['required', 'array', 'min:1'],
            'g7_resolutions.*.sources.*.source' => ['required', 'string'],
            'g7_resolutions.*.sources.*.level' => ['required', 'integer', 'min:1', 'max:5'],
            'g7_resolutions.*.final_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'g7_resolutions.*.reason' => ['nullable', 'string'],
            'procedure_note' => ['nullable', 'string'],
            'accompaniment_conditions' => ['nullable', 'string'],
            'narrative_clusters' => ['required', 'array', 'size:4'],
            'narrative_clusters.A' => ['nullable', 'string'],
            'narrative_clusters.B' => ['nullable', 'string'],
            'narrative_clusters.C' => ['nullable', 'string'],
            'narrative_clusters.D' => ['nullable', 'string'],
        ]);

        // Step 1: Load eligibility baseline from stored data
        $eligibilityRow = $runner->runAsService(function () use ($case, $input): ?object {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $case)
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
            return $this->error('ELIGIBILITY_VERSION_NOT_FOUND', 'Eligibility version not found for this assessment case.', 404);
        }

        // Reconstruct EligibilityDecisionSnapshot from canonical_input_json
        $canonicalInput = json_decode($eligibilityRow->canonical_input_json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($canonicalInput)) {
            return $this->error('INVALID_BASELINE', 'Eligibility baseline data is corrupt.', 500);
        }

        try {
            $baseline = EligibilityDecisionSnapshot::create($canonicalInput);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_BASELINE', 'Eligibility baseline reconstruction failed: '.$e->getMessage(), 500);
        }

        $baselineArray = $baseline->toArray();
        $systemLevels = [];
        foreach (self::ASPECTS as $aspect) {
            $systemLevels[$aspect] = $baselineArray['zone']['aspects'][$aspect]['level'];
        }

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
                return $this->error('OVERRIDE_INVALID', 'Level override for '.$override['aspect'].' is invalid: '.$e->getMessage(), 422);
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
                return $this->error('OVERRIDE_INVALID', 'Label override is invalid: '.$e->getMessage(), 422);
            }
        }

        // Step 3: Build ReviewedEligibilityDecision (produces system_levels + final_levels)
        try {
            $reviewed = ReviewedEligibilityDecision::create($baseline, $levelOverrides, $labelOverride);
        } catch (\InvalidArgumentException $e) {
            return $this->error('REVIEWED_ELIGIBILITY_INVALID', $e->getMessage(), 422);
        }

        // Step 4: Build G7 resolutions — validate client-supplied per-source data through policy
        $discrepancyPolicy = new AspectSourceDiscrepancyPolicy;
        $g7ByAspect = [];

        foreach ($input['g7_resolutions'] as $resolution) {
            $aspect = $resolution['aspect'];
            if (! in_array($aspect, self::ASPECTS, true)) {
                return $this->error('G7_INVALID', "G7 aspect {$aspect} is not canonical.", 422);
            }
            if (isset($g7ByAspect[$aspect])) {
                return $this->error('G7_INVALID', "Duplicate G7 resolution for aspect {$aspect}.", 422);
            }

            try {
                $discrepancy = $discrepancyPolicy->evaluate([
                    'aspect' => $aspect,
                    'sources' => $resolution['sources'],
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->error('G7_INVALID', "G7 discrepancy for {$aspect} is invalid: ".$e->getMessage(), 422);
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
                                $entry['discrepancy'], $systemLevels[$aspect],
                            );
                        } else {
                            $resolutions[] = G7AspectResolution::resolved(
                                $entry['discrepancy'], $systemLevels[$aspect],
                                $entry['final_level'], $entry['reason'],
                            );
                        }
                    } else {
                        if ($entry['final_level'] !== null || $entry['reason'] !== null) {
                            return $this->error('G7_INVALID', "G7 aspect {$aspect} is not review-required but has resolution data.", 422);
                        }
                        $resolutions[] = G7AspectResolution::notRequired(
                            $entry['discrepancy'], $systemLevels[$aspect],
                        );
                    }
                } catch (\InvalidArgumentException $e) {
                    return $this->error('G7_INVALID', "G7 resolution for {$aspect} is invalid: ".$e->getMessage(), 422);
                }
            } else {
                // Not supplied → default to NOT_REQUIRED with single-source discrepancy
                try {
                    $discrepancy = $discrepancyPolicy->evaluate([
                        'aspect' => $aspect,
                        'sources' => [['source' => 'CANONICAL', 'level' => $systemLevels[$aspect]]],
                    ]);
                } catch (\InvalidArgumentException $e) {
                    return $this->error('G7_INVALID', "G7 default discrepancy for {$aspect} is invalid.", 500);
                }
                if ($discrepancy['review_required']) {
                    return $this->error('G7_INVALID', "G7 aspect {$aspect} requires review but no resolution was supplied.", 422);
                }
                try {
                    $resolutions[] = G7AspectResolution::notRequired($discrepancy, $systemLevels[$aspect]);
                } catch (\InvalidArgumentException $e) {
                    return $this->error('G7_INVALID', "G7 default resolution for {$aspect} is invalid.", 500);
                }
            }
        }

        // Step 5: Build G7ReviewSet
        try {
            $g7ReviewSet = G7ReviewSet::fromResolutions($resolutions);
        } catch (\InvalidArgumentException $e) {
            return $this->error('G7_INVALID', 'G7 review set is invalid: '.$e->getMessage(), 422);
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
            return $this->error('SNAPSHOT_INVALID', 'Signing snapshot composition failed: '.$e->getMessage(), 422);
        }

        // Step 7: Evaluate prerequisites against DERIVED data (not client input)
        try {
            $prerequisiteResult = (new ReportSigningPrerequisitePolicy)->evaluate(
                $snapshot->prerequisiteInput(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('PREREQUISITE_INVALID', $e->getMessage(), 422);
        }

        if (! $prerequisiteResult['can_sign']) {
            return response()->json([
                'error' => [
                    'code' => 'SIGNING_BLOCKED',
                    'message' => 'Report cannot be signed. Resolve the listed blocking conditions.',
                    'blocking_reason_codes' => $prerequisiteResult['blocking_reason_codes'],
                ],
            ], 422);
        }

        // Step 8: Validate state transition
        try {
            $transitionResult = (new ReportSigningTransitionPolicy)->attempt('UNDER_REVIEW', $snapshot);
        } catch (\DomainException $e) {
            return $this->error('STATE_INVALID', $e->getMessage(), 422);
        }

        if (! $transitionResult['can_sign']) {
            return response()->json([
                'error' => [
                    'code' => 'SIGNING_BLOCKED',
                    'message' => 'Report cannot be signed.',
                    'blocking_reason_codes' => $transitionResult['blocking_reason_codes'],
                ],
            ], 422);
        }

        // Step 9: Persist snapshot_json = prerequisiteInput + provenance (derived, not client claims)
        $signedAt = now();
        $signedByAdminId = (int) $admin->id;
        $snapshotJson = json_encode([
            'prerequisite_input' => $snapshot->prerequisiteInput(),
            'provenance' => $snapshot->provenance(),
        ], JSON_THROW_ON_ERROR);

        $row = $runner->runAsService(function () use (
            $case, $input, $signedAt, $signedByAdminId, $snapshotJson,
        ): ?object {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $case)
                ->first();
            if ($caseRecord === null) {
                return null;
            }
            $caseId = (int) $caseRecord->id;

            $narrative = DB::table('bilingual_narrative_versions')
                ->where('id', $input['narrative_version_id'])
                ->where('assessment_case_id', $caseId)
                ->first();
            if ($narrative === null) {
                $error = new \stdClass;
                $error->code = 'NARRATIVE_VERSION_NOT_FOUND';
                return $error;
            }

            $latest = DB::table('report_signing_snapshots')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();
            $version = $latest === null ? 1 : $latest->version + 1;
            $supersedesId = $latest?->id;
            $id = (string) Str::ulid();

            DB::table('report_signing_snapshots')->insert([
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

            return DB::table('report_signing_snapshots')->where('id', $id)->sole();
        });

        if ($row === null) {
            return $this->error('CASE_NOT_FOUND', 'Assessment case not found.', 404);
        }
        if ($row instanceof \stdClass && isset($row->code)) {
            return $this->error($row->code, 'Referenced version does not belong to this assessment case.', 404);
        }

        return response()->json(['data' => [
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
        ]], 201);
    }

    public function show(string $case, RlsContextRunner $runner): JsonResponse
    {
        $row = $runner->runAsService(function () use ($case): ?object {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $case)
                ->first();
            if ($caseRecord === null) {
                return null;
            }
            return DB::table('report_signing_snapshots')
                ->where('assessment_case_id', (int) $caseRecord->id)
                ->orderByDesc('version')
                ->first();
        });

        if ($row === null) {
            return $this->error('CASE_NOT_FOUND', 'No signing snapshot found for this assessment case.', 404);
        }

        return response()->json(['data' => [
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
        ]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
