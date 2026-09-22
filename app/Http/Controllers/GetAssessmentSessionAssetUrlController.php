<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\GetAssessmentSessionAssetUrl;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). GET
 * /sessions/{id}/assets/{assetId}/url. Same sealed boundary as the other
 * session-http controllers -- no DB/Eloquent/RlsContextRunner dependency,
 * GetAssessmentSessionAssetUrl owns its own service-context elevation. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * Error-code -> HTTP status mirrors GetAssessmentSessionItemsController,
 * plus ASSET_NOT_FOUND (no reference row for this instrument+asset_id).
 * The JSON response itself carries `Cache-Control: no-store, private` --
 * distinct from the ServeFile-issued Cache-Control on the actual asset
 * fetch once the client follows the returned URL.
 */
final class GetAssessmentSessionAssetUrlController extends Controller
{
    private const REJECTION_STATUS = [
        'SESSION_NOT_FOUND' => 404,
        'SESSION_NOT_STARTED' => 409,
        'SESSION_CLOSED' => 409,
        'DEADLINE_EXCEEDED' => 409,
        'ASSET_NOT_FOUND' => 404,
    ];

    public function __invoke(
        Request $request,
        string $id,
        string $assetId,
        GetAssessmentSessionAssetUrl $action,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $result = $action->execute($principal->participantId, $id, $assetId);

            if ($result->accepted && $result->url !== null && $result->expiresAt !== null) {
                return $this->withNoStore(response()->json([
                    'url' => $result->url,
                    'expires_at' => $result->expiresAt->format(DATE_ATOM),
                ]));
            }

            $status = self::REJECTION_STATUS[$result->errorCode ?? ''] ?? null;
            if ($status === null) {
                report(new RuntimeException("Unmapped assessment session asset URL error code: {$result->errorCode}"));

                return $this->withNoStore($this->error(500, 'ASSESSMENT_SESSION_ASSET_URL_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.'));
            }

            return $this->withNoStore($this->error($status, $result->errorCode, $this->message($result->errorCode)));
        } catch (Throwable $exception) {
            report($exception);

            return $this->withNoStore($this->error(500, 'ASSESSMENT_SESSION_ASSET_URL_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.'));
        }
    }

    private function message(?string $errorCode): string
    {
        return match ($errorCode) {
            'SESSION_NOT_FOUND' => 'Sesi tidak ditemukan.',
            'SESSION_NOT_STARTED' => 'Sesi belum dimulai.',
            'SESSION_CLOSED' => 'Sesi sudah ditutup.',
            'DEADLINE_EXCEEDED' => 'Waktu pengerjaan sudah habis.',
            'ASSET_NOT_FOUND' => 'Aset tidak ditemukan.',
            default => 'URL aset tidak dapat diterbitkan.',
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }

    private function withNoStore(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
