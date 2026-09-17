<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\PaymentProvider;
use App\Data\Payments\PaymentWebhookInput;
use App\Enums\PaymentWebhookOutcome;
use App\Services\Payments\Exceptions\WebhookAuthenticationFailed;
use App\Services\Payments\Exceptions\WebhookPayloadRejected;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class XenditWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentProvider $provider,
        PaymentWebhookProcessor $processor,
    ): JsonResponse {
        $token = $request->header('x-callback-token');

        try {
            $event = $provider->normalizeWebhook(new PaymentWebhookInput(
                headers: ['x-callback-token' => is_string($token) ? $token : ''],
                payload: $request->json()->all(),
            ));
        } catch (WebhookAuthenticationFailed) {
            return $this->rejected(401);
        } catch (WebhookPayloadRejected) {
            return $this->rejected(422);
        }

        $result = $processor->process('xendit', $event);

        if ($result->outcome === PaymentWebhookOutcome::Conflict) {
            return response()->json([
                'error' => [
                    'code' => 'WEBHOOK_CONFLICT',
                    'message' => 'Webhook tidak dapat diproses.',
                ],
            ], 409);
        }

        if ($result->outcome === PaymentWebhookOutcome::Rejected
            && $result->reason !== 'invalid_transition') {
            return $this->rejected(422);
        }

        return response()->json(['status' => 'received']);
    }

    private function rejected(int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'WEBHOOK_REJECTED',
                'message' => 'Webhook ditolak.',
            ],
        ], $status);
    }
}
