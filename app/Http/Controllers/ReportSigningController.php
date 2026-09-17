<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Review\ReportSigningPrerequisitePolicy;
use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReportSigningController extends Controller
{
    public function sign(Request $request, string $case, RlsContextRunner $runner): JsonResponse
    {
        /** @var Admin|null $admin */
        $admin = $request->user('admin');

        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ReviewReports)) {
            return $this->error('FORBIDDEN', 'Only psychologists may sign reports.', 403);
        }

        $input = $request->validate([
            'eligibility_version_id' => ['required', 'string'],
            'narrative_version_id' => ['required', 'string'],
            'validity' => ['required', 'string', 'in:V1,V2,V3'],
            'procedure_note' => ['nullable', 'string'],
            'label' => ['nullable', 'string', 'in:DISARANKAN,DIPERTIMBANGKAN,TIDAK_DISARANKAN'],
            'accompaniment_conditions' => ['nullable', 'string'],
            'unresolved_g7_aspects' => ['present', 'array'],
            'overrides' => ['present', 'array'],
            'target_field' => ['nullable', 'string'],
            'narrative_clusters' => ['required', 'array', 'size:4'],
            'narrative_clusters.A' => ['nullable', 'string'],
            'narrative_clusters.B' => ['nullable', 'string'],
            'narrative_clusters.C' => ['nullable', 'string'],
            'narrative_clusters.D' => ['nullable', 'string'],
        ]);

        $prerequisiteInput = [
            'validity' => $input['validity'],
            'procedure_note' => $input['procedure_note'],
            'label' => $input['label'],
            'accompaniment_conditions' => $input['accompaniment_conditions'],
            'unresolved_g7_aspects' => $input['unresolved_g7_aspects'],
            'overrides' => $input['overrides'],
            'target_field' => $input['target_field'],
            'narrative_clusters' => $input['narrative_clusters'],
        ];

        try {
            $prerequisiteResult = (new ReportSigningPrerequisitePolicy)->evaluate($prerequisiteInput);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        if (! $prerequisiteResult['can_sign']) {
            return $this->error(
                'SIGNING_BLOCKED',
                'Report signing prerequisites are not met.',
                422,
                ['blocking_reason_codes' => $prerequisiteResult['blocking_reason_codes']],
            );
        }

        $signedAt = now();
        $signedByAdminId = $admin->id;

        $row = $runner->runAsService(function () use (
            $case, $input, $prerequisiteInput, $prerequisiteResult, $signedByAdminId, $signedAt,
        ): ?object {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $case)
                ->first();

            if ($caseRecord === null) {
                return null;
            }

            $caseId = (int) $caseRecord->id;

            $eligibility = DB::table('eligibility_decision_versions')
                ->where('id', $input['eligibility_version_id'])
                ->where('assessment_case_id', $caseId)
                ->first();

            if ($eligibility === null) {
                return (object) ['error' => 'ELIGIBILITY_VERSION_NOT_FOUND'];
            }

            $narrative = DB::table('bilingual_narrative_versions')
                ->where('id', $input['narrative_version_id'])
                ->where('assessment_case_id', $caseId)
                ->first();

            if ($narrative === null) {
                return (object) ['error' => 'NARRATIVE_VERSION_NOT_FOUND'];
            }

            $clusterChecksums = [];
            foreach (['A', 'B', 'C', 'D'] as $cluster) {
                $text = $input['narrative_clusters'][$cluster];
                $clusterChecksums[$cluster] = $text !== null && trim($text) !== ''
                    ? hash('sha256', $text)
                    : null;
            }

            $snapshotJson = [
                'type' => 'report_signing_snapshot',
                'prerequisite_input' => $prerequisiteInput,
                'prerequisite_provenance' => $prerequisiteResult['provenance'],
                'provenance' => [
                    'eligibility_version_id' => $input['eligibility_version_id'],
                    'narrative_version_id' => $input['narrative_version_id'],
                    'eligibility_snapshot' => json_decode($eligibility->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
                    'narrative_snapshot' => json_decode($narrative->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
                    'narrative_cluster_checksums' => $clusterChecksums,
                ],
                'signed_by_admin_id' => $signedByAdminId,
                'signed_at' => $signedAt->toISOString(),
            ];

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
                'snapshot_json' => json_encode($snapshotJson, JSON_THROW_ON_ERROR),
                'signed_by_admin_id' => $signedByAdminId,
                'signed_at' => $signedAt,
                'created_at' => now(),
            ]);

            return DB::table('report_signing_snapshots')->where('id', $id)->sole();
        });

        if ($row === null) {
            return $this->error('CASE_NOT_FOUND', 'Assessment case not found.', 404);
        }

        if (isset($row->error)) {
            $error = $row->error;
            if ($error === 'ELIGIBILITY_VERSION_NOT_FOUND') {
                return $this->error('ELIGIBILITY_VERSION_NOT_FOUND', 'Eligibility version does not belong to this case.', 404);
            }
            if ($error === 'NARRATIVE_VERSION_NOT_FOUND') {
                return $this->error('NARRATIVE_VERSION_NOT_FOUND', 'Narrative version does not belong to this case.', 404);
            }
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
            return $this->error('CASE_NOT_FOUND', 'Report signing snapshot not found for this assessment case.', 404);
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

    /**
     * @param array<mixed> $extra
     */
    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status);
    }
}
