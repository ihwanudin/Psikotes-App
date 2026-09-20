<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AdminAbility;
use App\Http\Requests\UpdateNarrativeClusterEditRequest;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NarrativeClusterEditController extends Controller
{
    /** @var list<string> */
    private const CLUSTERS = ['A', 'B', 'C', 'D'];

    public function show(Request $request, string $case, RlsContextRunner $runner): JsonResponse
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ReviewReports)) {
            abort(404);
        }

        // Minimal runAsService: assessment_cases RLS only allows service role to SELECT.
        $caseRecord = $runner->runAsService(function () use ($case): ?object {
            return DB::table('assessment_cases')
                ->where('public_id', $case)
                ->select('id', 'organization_id')
                ->first();
        });

        if ($caseRecord === null) {
            return $this->error('CASE_NOT_FOUND', 'Assessment case not found.', 404);
        }

        // Branch scope: branch_admin/staff only access cases in their own branch.
        $branchId = $admin->rlsContext()->branchId;
        if ($branchId !== null && (int) $caseRecord->organization_id !== $branchId) {
            abort(404);
        }

        $caseId = (int) $caseRecord->id;

        // runAsService required: bilingual_narrative_versions and narrative_cluster_edits
        // RLS only allows service role to SELECT.
        $result = $runner->runAsService(function () use ($caseId): array {
            $baseline = DB::table('bilingual_narrative_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            $edits = DB::table('narrative_cluster_edits')
                ->where('assessment_case_id', $caseId)
                ->get()
                ->keyBy('cluster');

            $clusters = [];
            foreach (self::CLUSTERS as $cluster) {
                $edit = $edits->get($cluster);
                $isStale = false;

                if ($edit !== null && $baseline !== null) {
                    $currentChecksum = hash('sha256', $baseline->snapshot_json);
                    $isStale = $edit->baseline_snapshot_checksum !== $currentChecksum;
                }

                $clusters[$cluster] = [
                    'baseline' => $baseline !== null ? $this->baselineText($baseline, $cluster) : null,
                    'edited' => $edit !== null ? $edit->edited_text : null,
                    'isStale' => $isStale,
                ];
            }

            return [
                'clusters' => $clusters,
                'baselineVersionId' => $baseline?->id,
            ];
        });

        return response()->json(['data' => $result]);
    }

    public function update(UpdateNarrativeClusterEditRequest $request, string $case, string $cluster, RlsContextRunner $runner): JsonResponse
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ReviewReports)) {
            abort(404);
        }

        $cluster = strtoupper($cluster);
        if (! in_array($cluster, self::CLUSTERS, true)) {
            return $this->error('VALIDATION_FAILED', 'Cluster must be one of A, B, C, D.', 422);
        }

        $input = $request->validated();

        $editedText = trim($input['edited_text']);
        if ($editedText === '') {
            return $this->error('VALIDATION_FAILED', 'Edited text must not be empty after trimming.', 422);
        }

        // Minimal runAsService: assessment_cases RLS only allows service role to SELECT.
        $caseRecord = $runner->runAsService(function () use ($case): ?object {
            return DB::table('assessment_cases')
                ->where('public_id', $case)
                ->select('id', 'organization_id')
                ->first();
        });

        if ($caseRecord === null) {
            return $this->error('CASE_NOT_FOUND', 'Assessment case not found.', 404);
        }

        // Branch scope: branch_admin/staff only access cases in their own branch.
        $branchId = $admin->rlsContext()->branchId;
        if ($branchId !== null && (int) $caseRecord->organization_id !== $branchId) {
            abort(404);
        }

        $caseId = (int) $caseRecord->id;

        try {
            // runAsService required: bilingual_narrative_versions and narrative_cluster_edits
            // RLS only allows service role to SELECT/INSERT/UPDATE.
            $row = $runner->runAsService(function () use ($caseId, $cluster, $editedText, $input): ?object {
                $baseline = DB::table('bilingual_narrative_versions')
                    ->where('id', $input['baseline_version_id'])
                    ->first();

                if ($baseline === null) {
                    return null;
                }

                if ((int) $baseline->assessment_case_id !== $caseId) {
                    throw new \InvalidArgumentException('Baseline version does not belong to this assessment case.');
                }

                $checksum = hash('sha256', $baseline->snapshot_json);

                $existing = DB::table('narrative_cluster_edits')
                    ->where('assessment_case_id', $caseId)
                    ->where('cluster', $cluster)
                    ->first();

                if ($existing !== null) {
                    DB::table('narrative_cluster_edits')
                        ->where('id', $existing->id)
                        ->update([
                            'edited_text' => $editedText,
                            'baseline_snapshot_checksum' => $checksum,
                            'baseline_version_id' => $input['baseline_version_id'],
                            'edited_at' => now(),
                        ]);

                    return DB::table('narrative_cluster_edits')->where('id', $existing->id)->sole();
                }

                $id = (string) Str::ulid();
                DB::table('narrative_cluster_edits')->insert([
                    'id' => $id,
                    'assessment_case_id' => $caseId,
                    'cluster' => $cluster,
                    'edited_text' => $editedText,
                    'baseline_snapshot_checksum' => $checksum,
                    'baseline_version_id' => $input['baseline_version_id'],
                    'edited_at' => now(),
                    'created_at' => now(),
                ]);

                return DB::table('narrative_cluster_edits')->where('id', $id)->sole();
            });
        } catch (\InvalidArgumentException $e) {
            return $this->error('BASELINE_CASE_MISMATCH', $e->getMessage(), 422);
        }

        if ($row === null) {
            return $this->error('BASELINE_NOT_FOUND', 'Baseline version not found.', 404);
        }

        return response()->json(['data' => [
            'id' => $row->id,
            'assessmentCaseId' => (int) $row->assessment_case_id,
            'cluster' => $row->cluster,
            'editedText' => $row->edited_text,
            'baselineSnapshotChecksum' => $row->baseline_snapshot_checksum,
            'baselineVersionId' => $row->baseline_version_id,
            'editedAt' => $row->edited_at,
        ]]);
    }

    private function baselineText(\stdClass $baseline, string $cluster): ?string
    {
        return match ($cluster) {
            'A' => $baseline->cluster_a_id,
            'B' => $baseline->cluster_b_id,
            'C' => $baseline->cluster_c_id,
            'D' => $baseline->cluster_d_id,
            default => null,
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
