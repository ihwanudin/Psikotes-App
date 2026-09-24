<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Proctoring\ProctoringPhotoUrlIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ProctoringPhotoAccessController extends Controller
{
    public function __invoke(
        Request $request,
        string $photo,
        ProctoringPhotoUrlIssuer $issuer,
    ): JsonResponse {
        $admin = $request->user('admin');

        abort_unless($admin instanceof Admin, 403);

        try {
            return response()->json($issuer->issue($admin, $photo));
        } catch (RuntimeException) {
            abort(403);
        }
    }
}
