<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssessmentResultController extends Controller
{
    public function __invoke(Request $request, string $externalCandidateId, RlsContextRunner $runner): JsonResponse
    {
        $client = $request->attributes->get('integration_client');
        abort_unless($client instanceof IntegrationClient, 401);

        if (! in_array($client->result_delivery_mode, ['POLL', 'CALLBACK_AND_POLL'], true)) {
            return $this->error('RESULT_POLL_NOT_ALLOWED', 'Client tidak diizinkan mengambil hasil.', 403);
        }

        $allowedQuery = ['externalProcessId', 'assessmentRoundId'];
        if (array_diff(array_keys($request->query()), $allowedQuery) !== []) {
            return $this->error('VALIDATION_FAILED', 'Filter hasil tidak valid.', 422);
        }

        foreach ($allowedQuery as $key) {
            $value = $request->query($key);
            if ($value !== null && (! is_string($value) || strlen($value) > 100 || ! preg_match('/^[A-Za-z0-9._\/-]+$/', $value))) {
                return $this->error('VALIDATION_FAILED', 'Filter hasil tidak valid.', 422);
            }
        }

        $assessment = $runner->run(new RlsContext('service'), function () use ($request, $client, $externalCandidateId): ?AssessmentParticipant {
            return AssessmentParticipant::query()
                ->where('integration_client_id', $client->id)
                ->where('external_candidate_id', $externalCandidateId)
                ->when($request->query('externalProcessId'), fn ($query, $value) => $query->where('external_process_id', $value))
                ->when($request->query('assessmentRoundId'), fn ($query, $value) => $query->where('assessment_round_id', $value))
                ->latest('id')->first();
        });

        if ($assessment === null) {
            return $this->error('RESULT_NOT_FOUND', 'Hasil asesmen tidak ditemukan.', 404);
        }

        return response()->json(['data' => [
            'participantId' => (string) $assessment->participant_id,
            'externalCandidateId' => $assessment->external_candidate_id,
            'externalProcessId' => $assessment->external_process_id,
            'assessmentRoundId' => $assessment->assessment_round_id,
            'assessmentStatus' => $assessment->assessment_status,
            'recommendation' => $assessment->recommendation,
            'resultVersion' => $assessment->result_version,
            'finalizedAt' => $assessment->finalized_at?->toISOString(),
            'revokedAt' => $assessment->revoked_at?->toISOString(),
        ]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
