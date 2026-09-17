<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Registration\ConfirmIntegratedCheckout;
use App\Http\Requests\ConfirmIntegratedCheckoutRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use LogicException;

/** Internal P16 HTTP adapter; no production route is registered by this increment. */
final class IntegratedCheckoutConfirmationController extends Controller
{
    public function __invoke(
        ConfirmIntegratedCheckoutRequest $request,
        ConfirmIntegratedCheckout $confirmation,
    ): JsonResponse {
        try {
            $result = $confirmation->execute($request->toInput());
        } catch (DomainException) {
            return $this->error(
                'CHECKOUT_CONFIRMATION_CONFLICT',
                'Konfirmasi checkout tidak dapat diproses.',
                409,
            );
        } catch (LogicException) {
            return $this->error(
                'CHECKOUT_CONFIRMATION_UNAVAILABLE',
                'Konfirmasi checkout tidak tersedia.',
                503,
            );
        }

        return response()->json(['data' => [
            'confirmed' => true,
            'replayed' => $result['replayed'],
        ]]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
