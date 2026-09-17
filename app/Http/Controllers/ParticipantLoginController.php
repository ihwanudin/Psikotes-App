<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ParticipantLoginRequest;
use App\Services\ParticipantAuth\Exceptions\InvalidParticipantCredentials;
use App\Services\ParticipantAuth\Exceptions\ParticipantCredentialLocked;
use App\Services\ParticipantAuth\ParticipantLogin;
use Illuminate\Http\JsonResponse;

final class ParticipantLoginController extends Controller
{
    public function __invoke(ParticipantLoginRequest $request, ParticipantLogin $login): JsonResponse
    {
        try {
            $jwt = $login->attempt(
                (string) $request->validated('test_number'),
                (string) $request->validated('birth_date'),
            );
        } catch (InvalidParticipantCredentials) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Nomor tes atau tanggal lahir tidak valid.',
                ],
            ], 401);
        } catch (ParticipantCredentialLocked $exception) {
            return response()->json([
                'error' => [
                    'code' => 'CREDENTIAL_LOCKED',
                    'message' => 'Terlalu banyak percobaan. Silakan coba lagi nanti.',
                ],
            ], 429, ['Retry-After' => (string) $exception->retryAfter]);
        }

        return response()->json(['jwt' => $jwt]);
    }
}
