<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\ProvisionCheckoutParticipant;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Http\JsonResponse;

/** Not routed in production; requires the existing integration.client authentication middleware. */
final class CheckoutParticipantProvisioningController extends Controller
{
    public function __invoke(
        ProvisionCheckoutParticipantRequest $request,
        ProvisionCheckoutParticipant $provision,
        RlsContextRunner $runner,
    ): JsonResponse {
        $client = $request->attributes->get('integration_client');
        if (! $client instanceof IntegrationClient || ! $client->exists) {
            return $this->error('INVALID_SIGNATURE', 401);
        }
        // HTTP integration entry must not elevate an existing participant/admin context.
        if ($runner->current() !== null) {
            return $this->error('INTEGRATION_CONTEXT_INVALID', 403);
        }

        try {
            $result = $runner->run(new RlsContext('service'), fn (): array => $provision->handle($request));
        } catch (IdempotencyConflict) {
            return $this->error('IDEMPOTENCY_CONFLICT', 409);
        } catch (IntegrationContractViolation $exception) {
            return $this->error($exception->errorCode, $exception->httpStatus);
        }

        return response()->json(['data' => [
            'participantId' => (string) $result['participant_id'],
            'assessmentAttemptId' => $result['assessment_attempt_id'],
            'assessmentStatus' => $result['assessment_status'],
        ]], $result['replayed'] ? 200 : 201)->header('Cache-Control', 'no-store, private');
    }

    private function error(string $code, int $status): JsonResponse
    {
        return response()->json(['error' => [
            'code' => $code,
            'message' => 'Permintaan integrasi tidak dapat diproses.',
        ]], $status)->header('Cache-Control', 'no-store, private');
    }
}
