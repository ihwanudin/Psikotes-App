<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\ExecuteCheckoutPayment;
use App\Http\Requests\CheckoutPaymentRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use LogicException;

/** Thin private-checkout adapter; authority is reloaded by the credential-bound command. */
final class CheckoutPaymentController extends Controller
{
    public function __invoke(
        CheckoutPaymentRequest $request,
        ExecuteCheckoutPayment $payment,
    ): JsonResponse {
        try {
            $result = $payment->execute(
                $request->mutationCredentials(),
                $request->consultationRequested(),
            );
        } catch (DomainException) {
            return $this->error(
                'CHECKOUT_PAYMENT_UNAVAILABLE',
                'Pembayaran checkout tidak dapat diproses.',
                409,
            );
        } catch (LogicException) {
            return $this->error(
                'CHECKOUT_PAYMENT_UNAVAILABLE',
                'Pembayaran checkout tidak tersedia.',
                503,
            );
        }

        return response()->json(['data' => [
            'paymentState' => $result->state,
            'paymentUrl' => $result->paymentUrl,
        ]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
