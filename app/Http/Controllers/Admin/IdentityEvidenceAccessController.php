<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Identity\IdentityEvidenceUrlIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IdentityEvidenceAccessController extends Controller
{
    public function __invoke(
        Request $request,
        string $evidence,
        IdentityEvidenceUrlIssuer $issuer,
    ): JsonResponse {
        $admin = $request->user('admin');

        abort_unless($admin instanceof Admin, 403);

        return response()->json($issuer->issue($admin, $evidence));
    }
}
