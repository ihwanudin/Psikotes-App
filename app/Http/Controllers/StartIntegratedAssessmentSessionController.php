<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\RequiresRlsContext;
use App\Http\Requests\StartAssessmentSessionRequest;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\ParticipantAuth\ParticipantEntitlementGate;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * F2 S5 (2026-09-21). The AssessmentPrincipal/Integrated branch is NOT part of the
 * ADR-0030 cutover: it has no production route or middleware decision yet (ADR-0030
 * "Consequences" — "Jalur AssessmentPrincipal terintegrasi tetap belum menjadi route
 * produksi"). This class is the pre-cutover controller, renamed so the real
 * `StartParticipantSessionController` name can carry the ADR-0030-compliant
 * implementation without any DB/Eloquent/RlsContextRunner/entitlement dependency,
 * which this controller still legitimately has for its own (test-only) branch. Its
 * only caller today is the test-only route in
 * tests/Feature/Auth/AssessmentSessionAuthorizationTest.php; unchanged behaviour,
 * unchanged tests except the class name reference.
 */
final class StartIntegratedAssessmentSessionController extends Controller implements RequiresRlsContext
{
    public function __invoke(
        StartAssessmentSessionRequest $request,
        string $testType,
        ParticipantEntitlementGate $gate,
        AssessmentEntitlementGate $assessmentGate,
        RlsContextRunner $runner,
    ): JsonResponse {
        try {
            if ($request->attributes->has('assessment_principal')) {
                $principal = $request->attributes->get('assessment_principal');
                if (! $principal instanceof AssessmentPrincipal) {
                    throw new UnauthorizedHttpException('Bearer');
                }
                // Read-only adapter: a future engine must recheck inside its locking transaction.
                $runner->runAsService(fn () => $assessmentGate->assertReady($principal, $testType));
            } else {
                $principal = $request->attributes->get('participant_principal');
                if (! $principal instanceof ParticipantPrincipal) {
                    throw new UnauthorizedHttpException('Bearer');
                }
                $gate->assertReady($principal->participantId, $testType);
            }
        } catch (EntitlementLocked) {
            return response()->json([
                'error' => [
                    'code' => 'ENTITLEMENT_LOCKED',
                    'message' => 'Tes belum dapat dimulai karena akses belum aktif.',
                ],
            ], 403);
        }

        return response()->json([
            'error' => [
                'code' => 'SESSION_ENGINE_PENDING',
                'message' => 'Akses tes aktif, tetapi mesin sesi belum tersedia pada fase ini.',
            ],
        ], 501);
    }
}
