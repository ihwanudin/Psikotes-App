<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Enums\AdminAbility;
use App\Http\Requests\StoreEligibilityDecisionRequest;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EligibilityDecisionController extends Controller
{
    public function store(StoreEligibilityDecisionRequest $request, string $case, RlsContextRunner $runner): JsonResponse
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ReviewReports)) {
            abort(404);
        }

        $input = $request->validated();
        $input['iq'] = (int) $input['iq'];

        try {
            $snapshot = EligibilityDecisionSnapshot::create($input);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        $data = $snapshot->toArray();
        $canonical = [
            'levels' => $input['levels'],
            'field_code' => $input['field_code'],
            'iq' => $input['iq'],
            'validity' => $input['validity'],
            'standard_configuration' => $input['standard_configuration'],
            'eligibility_source_versions' => $input['eligibility_source_versions'],
        ];

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

        // runAsService required: eligibility_decision_versions RLS only allows service role to INSERT/SELECT.
        $row = $runner->runAsService(function () use ($caseId, $data, $canonical): object {
            $latest = DB::table('eligibility_decision_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            $version = $latest === null ? 1 : $latest->version + 1;
            $supersedesId = $latest?->id;
            $id = (string) Str::ulid();

            DB::table('eligibility_decision_versions')->insert([
                'id' => $id,
                'assessment_case_id' => $caseId,
                'version' => $version,
                'supersedes_id' => $supersedesId,
                'standard_version' => $data['provenance']['eligibility_standard_version'],
                'field_code' => $data['zone']['field_code'],
                'publication_blocked' => $data['publication_blocked'],
                'recommendation_label' => $data['recommendation']['label'] ?? null,
                'iq' => $canonical['iq'],
                'validity' => $canonical['validity'],
                'snapshot_json' => json_encode($data, JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode($canonical, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return DB::table('eligibility_decision_versions')->where('id', $id)->sole();
        });

        return response()->json(['data' => [
            'id' => $row->id,
            'assessmentCaseId' => (int) $row->assessment_case_id,
            'version' => (int) $row->version,
            'standardVersion' => $row->standard_version,
            'fieldCode' => $row->field_code,
            'publicationBlocked' => (bool) $row->publication_blocked,
            'recommendationLabel' => $row->recommendation_label,
            'iq' => (int) $row->iq,
            'validity' => $row->validity,
            'snapshot' => json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
            'createdAt' => $row->created_at,
        ]], 201);
    }

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

        // runAsService required: eligibility_decision_versions RLS only allows service role to SELECT.
        $row = $runner->runAsService(function () use ($caseId): ?object {
            return DB::table('eligibility_decision_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();
        });

        if ($row === null) {
            return $this->error('CASE_NOT_FOUND', 'Eligibility decision not found for this assessment case.', 404);
        }

        return response()->json(['data' => [
            'id' => $row->id,
            'assessmentCaseId' => (int) $row->assessment_case_id,
            'version' => (int) $row->version,
            'standardVersion' => $row->standard_version,
            'fieldCode' => $row->field_code,
            'publicationBlocked' => (bool) $row->publication_blocked,
            'recommendationLabel' => $row->recommendation_label,
            'iq' => (int) $row->iq,
            'validity' => $row->validity,
            'snapshot' => json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR),
            'createdAt' => $row->created_at,
        ]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
