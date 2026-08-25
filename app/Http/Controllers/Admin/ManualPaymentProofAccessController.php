<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Payments\ManualPaymentProofUrlIssuer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ManualPaymentProofAccessController extends Controller
{
    public function __invoke(
        Request $request,
        string $order,
        ManualPaymentProofUrlIssuer $issuer,
    ): RedirectResponse {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return redirect()->away($issuer->issue($admin, $order));
    }
}
