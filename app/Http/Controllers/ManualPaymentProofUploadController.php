<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payments\StoreManualPaymentProof;
use App\Http\Requests\StoreManualPaymentProofRequest;
use Illuminate\Http\RedirectResponse;

final class ManualPaymentProofUploadController extends Controller
{
    public function __invoke(
        StoreManualPaymentProofRequest $request,
        StoreManualPaymentProof $store,
    ): RedirectResponse {
        $store->handle(
            (int) $request->session()->get('registration.participant_id'),
            $request->file('payment_proof'),
        );

        return redirect()
            ->route('registration.received')
            ->with('status', 'manual-payment-proof-stored');
    }
}
