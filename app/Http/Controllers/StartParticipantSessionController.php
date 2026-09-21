<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\StartParticipantAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\AssessmentSessionDefinitionUnavailable;
use App\Domain\AssessmentSessions\AssessmentSessionStartFailureCode;
use App\Domain\AssessmentSessions\AssessmentSessionStartRetriesExhausted;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionDefinitionCatalog;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Http\Requests\StartGenericAssessmentSessionRequest;
use App\Http\Resources\AssessmentSessionResource;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * ADR-0030 sealed HTTP boundary for the generic participant session-start command.
 * This controller must never gain a DB, Eloquent, RlsContextRunner, or entitlement
 * dependency: the trusted command it calls owns its own service transaction and
 * rejects any pre-existing context before touching SQL
 * (StartParticipantAssessmentSession::assertCleanOuterBoundary()). Adding a
 * participant-scoped read here -- even a read-only one -- would establish exactly
 * the stacked-context boundary ADR-0030 exists to prevent; see
 * tests/Architecture/AssessmentSessionStartBoundaryTest.php, which enforces this
 * statically.
 *
 * The Integrated/AssessmentPrincipal branch that used to live in this class is now
 * StartIntegratedAssessmentSessionController -- not part of this route, not part of
 * this cutover (ADR-0030: "Jalur AssessmentPrincipal terintegrasi tetap belum
 * menjadi route produksi").
 *
 * This is the ONLY exception-to-HTTP adapter for the start command; the domain
 * command never returns an error envelope itself.
 */
final class StartParticipantSessionController extends Controller
{
    public function __invoke(
        StartGenericAssessmentSessionRequest $request,
        string $testType,
        StartParticipantAssessmentSession $command,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $instrument = GenericAssessmentInstrument::fromExternal($testType);
            $result = $command->execute($principal, $instrument);

            return response()->json((new AssessmentSessionResource($result))->resolve());
        } catch (UnsupportedGenericAssessmentInstrument) {
            return $this->error(422, 'INVALID_ASSESSMENT_START_REQUEST', 'Permintaan mulai tes tidak valid.');
        } catch (CaseAuthorizationRejected) {
            return $this->notAvailable();
        } catch (InvalidAssessmentSessionState $exception) {
            return match ($exception->httpFailureCode) {
                AssessmentSessionStartFailureCode::NotAvailable => $this->notAvailable(),
                AssessmentSessionStartFailureCode::Conflict => $this->conflict(),
                null => $this->internalError($exception),
            };
        } catch (AssessmentSessionDefinitionUnavailable|InvalidAssessmentSessionDefinitionCatalog) {
            return $this->definitionUnavailable();
        } catch (AssessmentItemContentUnavailable) {
            return $this->itemContentUnavailable();
        } catch (AssessmentSessionStartRetriesExhausted) {
            return $this->error(503, 'ASSESSMENT_START_TEMPORARILY_UNAVAILABLE', 'Server sedang sibuk. Coba lagi sesaat lagi.');
        } catch (Throwable $exception) {
            return $this->internalError($exception);
        }
    }

    private function notAvailable(): JsonResponse
    {
        return $this->error(403, 'ASSESSMENT_NOT_AVAILABLE', 'Tes ini tidak tersedia untuk dimulai.');
    }

    private function conflict(): JsonResponse
    {
        return $this->error(409, 'ASSESSMENT_START_CONFLICT', 'Permintaan mulai tes berbenturan. Coba lagi.');
    }

    private function definitionUnavailable(): JsonResponse
    {
        return $this->error(503, 'ASSESSMENT_DEFINITION_UNAVAILABLE', 'Definisi tes belum tersedia. Coba lagi nanti.');
    }

    private function itemContentUnavailable(): JsonResponse
    {
        return $this->error(503, 'ASSESSMENT_ITEM_CONTENT_UNAVAILABLE', 'Isi soal belum tersedia. Coba lagi nanti.');
    }

    /**
     * Untyped InvalidAssessmentSessionState and every other unexpected Throwable
     * land here: an invariant/code-bug, not a participant outcome (Correction A).
     * Logged with full detail server-side -- the response body never carries the
     * message, exception class, SQL, case, tenant, grant, or definition payload.
     */
    private function internalError(Throwable $exception): JsonResponse
    {
        report($exception);

        return $this->error(500, 'ASSESSMENT_START_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
