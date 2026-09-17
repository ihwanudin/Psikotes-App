<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Narrative\BilingualClusterNarrativeComposer;
use App\Domain\Narrative\ReportingNarrativeCatalog;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BilingualNarrativeController extends Controller
{
    public function store(Request $request, string $case, RlsContextRunner $runner): JsonResponse
    {
        $input = $request->validate([
            'aspects' => ['required', 'array', 'size:18'],
        ]);

        $catalog = $this->catalog();
        $composer = new BilingualClusterNarrativeComposer($catalog);

        try {
            $result = $composer->compose($input);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        $caseId = $runner->runAsService(function () use ($case): ?int {
            $caseRecord = DB::table('assessment_cases')
                ->where('public_id', $case)
                ->first();

            return $caseRecord === null ? null : (int) $caseRecord->id;
        });

        if ($caseId === null) {
            return $this->error('CASE_NOT_FOUND', 'Assessment case not found.', 404);
        }

        $row = $runner->runAsService(function () use ($caseId, $result): object {
            $latest = DB::table('bilingual_narrative_versions')
                ->where('assessment_case_id', $caseId)
                ->orderByDesc('version')
                ->first();

            $version = $latest === null ? 1 : $latest->version + 1;
            $supersedesId = $latest?->id;
            $id = (string) Str::ulid();

            DB::table('bilingual_narrative_versions')->insert([
                'id' => $id,
                'assessment_case_id' => $caseId,
                'version' => $version,
                'supersedes_id' => $supersedesId,
                'eligibility_version_id' => null,
                'review_required' => $result['review_required'],
                'cluster_a_id' => $result['clusters']['A']['id']['narrative'] ?? null,
                'cluster_a_jp' => $result['clusters']['A']['jp']['narrative'] ?? null,
                'cluster_b_id' => $result['clusters']['B']['id']['narrative'] ?? null,
                'cluster_b_jp' => $result['clusters']['B']['jp']['narrative'] ?? null,
                'cluster_c_id' => $result['clusters']['C']['id']['narrative'] ?? null,
                'cluster_c_jp' => $result['clusters']['C']['jp']['narrative'] ?? null,
                'cluster_d_id' => $result['clusters']['D']['id']['narrative'] ?? null,
                'cluster_d_jp' => $result['clusters']['D']['jp']['narrative'] ?? null,
                'snapshot_json' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return DB::table('bilingual_narrative_versions')->where('id', $id)->sole();
        });

        return response()->json(['data' => [
            'id' => $row->id,
            'assessmentCaseId' => (int) $row->assessment_case_id,
            'version' => (int) $row->version,
            'eligibilityVersionId' => $row->eligibility_version_id,
            'reviewRequired' => (bool) $row->review_required,
            'clusters' => [
                'A' => ['id' => $row->cluster_a_id, 'jp' => $row->cluster_a_jp],
                'B' => ['id' => $row->cluster_b_id, 'jp' => $row->cluster_b_jp],
                'C' => ['id' => $row->cluster_c_id, 'jp' => $row->cluster_c_jp],
                'D' => ['id' => $row->cluster_d_id, 'jp' => $row->cluster_d_jp],
            ],
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

            return DB::table('bilingual_narrative_versions')
                ->where('assessment_case_id', (int) $caseRecord->id)
                ->orderByDesc('version')
                ->first();
        });

        if ($row === null) {
            return $this->error('CASE_NOT_FOUND', 'Bilingual narrative not found for this assessment case.', 404);
        }

        return response()->json(['data' => [
            'id' => $row->id,
            'assessmentCaseId' => (int) $row->assessment_case_id,
            'version' => (int) $row->version,
            'eligibilityVersionId' => $row->eligibility_version_id,
            'reviewRequired' => (bool) $row->review_required,
            'clusters' => [
                'A' => ['id' => $row->cluster_a_id, 'jp' => $row->cluster_a_jp],
                'B' => ['id' => $row->cluster_b_id, 'jp' => $row->cluster_b_jp],
                'C' => ['id' => $row->cluster_c_id, 'jp' => $row->cluster_c_jp],
                'D' => ['id' => $row->cluster_d_id, 'jp' => $row->cluster_d_jp],
            ],
            'createdAt' => $row->created_at,
        ]]);
    }

    private function catalog(): ReportingNarrativeCatalog
    {
        $path = database_path('seeders/data/reporting.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException('Reporting data could not be read.');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)
            || ! isset($decoded['narratives'], $decoded['connectors'])
            || ! is_array($decoded['narratives'])
            || ! is_array($decoded['connectors'])) {
            throw new \RuntimeException('Reporting data has an invalid top-level shape.');
        }

        /** @var array{narratives: list<array{key: string, aspect: string, level: int, id: string, jp: string}>, connectors: list<array{group: string, order: int, text: string}>} $decoded */
        return new ReportingNarrativeCatalog($decoded['narratives'], $decoded['connectors']);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
