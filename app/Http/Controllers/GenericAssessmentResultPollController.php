<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IntegrationClient;
use App\Services\Integrations\GenericAssessmentResultPollProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GenericAssessmentResultPollController extends Controller
{
    public function __invoke(
        Request $request,
        string $assessmentAttemptId,
        GenericAssessmentResultPollProjection $poll,
    ): JsonResponse {
        $client = $request->attributes->get('integration_client');
        abort_unless($client instanceof IntegrationClient, 401);

        [$version, $checksum] = $this->cursor($request);

        return response()->json(['data' => $poll->project(
            $client->id,
            $assessmentAttemptId,
            $version,
            $checksum,
        )]);
    }

    /** @return array{?int,?string} */
    private function cursor(Request $request): array
    {
        if (array_diff(array_keys($request->query()), ['version', 'checksum']) !== []) {
            return [-1, null];
        }
        $version = $request->query('version');
        $checksum = $request->query('checksum');
        if ($version === null && $checksum === null) {
            return [null, null];
        }
        if (! is_string($version) || preg_match('/^[1-9]\d{0,8}$/', $version) !== 1
            || ! is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            return [-1, null];
        }

        return [(int) $version, $checksum];
    }
}
