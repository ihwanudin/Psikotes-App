<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReviewInputController extends Controller
{
    public function __invoke(int $caseId, RlsContextRunner $runner): JsonResponse
    {
        [$eligibility, $narrative] = $runner->runAsService(function () use ($caseId): array {
            $eligibility = DB::table('eligibility_decision_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            $narrative = DB::table('bilingual_narrative_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            return [$eligibility, $narrative];
        });

        if ($eligibility === null && $narrative === null) {
            return $this->error('NOT_FOUND', 'No eligibility decision or narrative found for this assessment case.', 404);
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
                'clusters' => [
                    'A' => ['id' => $narrative->cluster_a_id, 'jp' => $narrative->cluster_a_jp],
                    'B' => ['id' => $narrative->cluster_b_id, 'jp' => $narrative->cluster_b_jp],
                    'C' => ['id' => $narrative->cluster_c_id, 'jp' => $narrative->cluster_c_jp],
                    'D' => ['id' => $narrative->cluster_d_id, 'jp' => $narrative->cluster_d_jp],
                ],
                'createdAt' => $narrative->created_at,
            ] : null,
        ]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
