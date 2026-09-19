<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReviewInputController extends Controller
{
    public function __invoke(Request $request, string $case, RlsContextRunner $runner): JsonResponse
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
            return $this->error('NOT_FOUND', 'No eligibility decision or narrative found for this assessment case.', 404);
        }

        // Branch scope: branch_admin/staff only access cases in their own branch.
        $branchId = $admin->rlsContext()->branchId;
        if ($branchId !== null && (int) $caseRecord->organization_id !== $branchId) {
            abort(404);
        }

        $caseId = (int) $caseRecord->id;

        // runAsService required: eligibility_decision_versions, bilingual_narrative_versions,
        // and narrative_cluster_edits RLS only allow service role to SELECT.
        [$eligibility, $narrative, $edits] = $runner->runAsService(function () use ($caseId): array {
            $eligibility = DB::table('eligibility_decision_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            $narrative = DB::table('bilingual_narrative_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            $edits = DB::table('narrative_cluster_edits')
                ->where('assessment_case_id', $caseId)
                ->get()
                ->keyBy('cluster');

            return [$eligibility, $narrative, $edits];
        });

        if ($eligibility === null && $narrative === null) {
            return $this->error('NOT_FOUND', 'No eligibility decision or narrative found for this assessment case.', 404);
        }

        $currentChecksum = $narrative !== null ? hash('sha256', $narrative->snapshot_json) : null;
        $clusters = [];
        foreach (['A', 'B', 'C', 'D'] as $cluster) {
            $edit = $edits->get($cluster);
            $baselineId = $this->baselineClusterText($narrative, $cluster);
            $baselineJp = $this->baselineClusterJp($narrative, $cluster);
            $isStale = $edit !== null
                && $currentChecksum !== null
                && $edit->baseline_snapshot_checksum !== $currentChecksum;

            $clusters[$cluster] = [
                'id' => $edit !== null ? $edit->edited_text : $baselineId,
                'jp' => $baselineJp,
                'isEdited' => $edit !== null,
                'isStale' => $isStale,
            ];
        }

        return response()->json(['data' => [
            'eligibility' => $eligibility !== null ? [
                'id' => $eligibility->id,
                'version' => (int) $eligibility->version,
                'standardVersion' => $eligibility->standard_version,
                'fieldCode' => $eligibility->field_code,
                'publicationBlocked' => (bool) $eligibility->publication_blocked,
                'recommendationLabel' => $eligibility->recommendation_label,
                'iq' => (int) $eligibility->iq,
                'validity' => $eligibility->validity,
                'snapshot' => json_decode($eligibility->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
                'createdAt' => $eligibility->created_at,
            ] : null,
            'narrative' => $narrative !== null ? [
                'id' => $narrative->id,
                'version' => (int) $narrative->version,
                'eligibilityVersionId' => $narrative->eligibility_version_id,
                'reviewRequired' => (bool) $narrative->review_required,
                'clusters' => $clusters,
                'createdAt' => $narrative->created_at,
            ] : null,
        ]]);
    }

    private function baselineClusterText(?\stdClass $narrative, string $cluster): ?string
    {
        if ($narrative === null) {
            return null;
        }

        return match ($cluster) {
            'A' => $narrative->cluster_a_id,
            'B' => $narrative->cluster_b_id,
            'C' => $narrative->cluster_c_id,
            'D' => $narrative->cluster_d_id,
            default => null,
        };
    }

    private function baselineClusterJp(?\stdClass $narrative, string $cluster): ?string
    {
        if ($narrative === null) {
            return null;
        }

        return match ($cluster) {
            'A' => $narrative->cluster_a_jp,
            'B' => $narrative->cluster_b_jp,
            'C' => $narrative->cluster_c_jp,
            'D' => $narrative->cluster_d_jp,
            default => null,
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
