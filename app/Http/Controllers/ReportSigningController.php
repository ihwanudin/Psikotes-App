<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Services\Review\ReportSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportSigningController extends Controller
{
    public function sign(Request $request, string $case, ReportSigningService $service): JsonResponse
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
            'revision_reason' => ['nullable', 'string'],
        ]);

        $result = $service->sign($case, $admin, $input);

        if (! $result['success']) {
            if ($result['code'] === 'SIGNING_BLOCKED' && isset($result['blocking_reason_codes'])) {
                return response()->json([
                    'error' => [
                        'code' => $result['code'],
                        'message' => $result['message'],
                        'blocking_reason_codes' => $result['blocking_reason_codes'],
                    ],
                ], $result['status']);
            }

            return $this->error($result['code'], $result['message'], $result['status']);
        }

        return response()->json(['data' => $result['data']], 201);
    }

    public function show(string $case, ReportSigningService $service): JsonResponse
    {
        $row = $service->latest($case);

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
