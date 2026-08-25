<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\StoreIdentityEvidence;
use App\Http\Requests\StoreIdentityEvidenceRequest;
use Illuminate\Http\RedirectResponse;

final class IdentityEvidenceUploadController extends Controller
{
    public function __invoke(
        StoreIdentityEvidenceRequest $request,
        StoreIdentityEvidence $store,
    ): RedirectResponse {
        $store->handle(
            (int) $request->session()->get('registration.participant_id'),
            $request->file('identity_document'),
            $request->file('initial_selfie'),
        );

        return redirect()
            ->route('registration.received')
            ->with('status', 'identity-evidence-stored');
    }
}
