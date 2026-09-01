<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Payments\ReviewAssessmentBillTransfer;
use App\Data\Payments\AssessmentBillManualReview;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use App\Enums\AssessmentBillManualReviewError;
use App\Exceptions\AssessmentBillManualReviewException;
use App\Http\Requests\ReviewAssessmentBillTransferRequest;
use App\Models\Admin;
use App\Services\Payments\AssessmentBillProofUrlIssuer;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** Internal HTTP adapter only. No production route or reviewer UI is registered in P11c2c. */
final class AssessmentBillManualReviewController extends Controller
{
    /** Opens only the opaque private URL returned by the two-phase issuer. */
    public function proof(Request $request, string $billReference,
        AssessmentBillProofUrlIssuer $issuer): Response
    {
        $actor = $request->user('admin');
        if (! $actor instanceof Admin) {
            return $this->proofError(404);
        }

        try {
            $access = $issuer->issue($actor, $billReference);
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND' => $this->proofError(404),
                'ASSESSMENT_BILL_PROOF_ACCESS_UNAVAILABLE' => $this->proofError(503),
                default => throw $exception,
            };
        }

        return $this->privateResponse(redirect()->away($access->url));
    }

    /** Future web wiring must remain POST + CSRF protected; tests register that route locally. */
    public function review(ReviewAssessmentBillTransferRequest $request, string $billReference,
        ReviewAssessmentBillTransfer $reviews): JsonResponse
    {
        $actor = $request->user('admin');
        if (! $actor instanceof Admin) {
            return $this->reviewError(404, 'ASSESSMENT_BILL_REVIEW_NOT_FOUND');
        }
        $values = $request->validated();

        try {
            $decision = AssessmentBillManualDecision::from((string) $values['decision']);
            $code = isset($values['rejection_code']) && is_string($values['rejection_code'])
                ? AssessmentBillManualRejectionCode::from($values['rejection_code']) : null;
            $review = new AssessmentBillManualReview(
                actorAdminId: $actor->id,
                billReference: $billReference,
                expectedProofFingerprint: (string) $values['proof_fingerprint'],
                decision: $decision,
                rejectionCode: $code,
            );
        } catch (InvalidArgumentException) {
            return $this->reviewError(404, 'ASSESSMENT_BILL_REVIEW_NOT_FOUND');
        }

        try {
            $result = $reviews->execute($review);
        } catch (AssessmentBillManualReviewException $exception) {
            return match ($exception->error) {
                AssessmentBillManualReviewError::NotFound => $this->reviewError(404, 'ASSESSMENT_BILL_REVIEW_NOT_FOUND'),
                AssessmentBillManualReviewError::Conflict => $this->reviewError(409, 'ASSESSMENT_BILL_REVIEW_CONFLICT'),
                AssessmentBillManualReviewError::StateInvalid,
                AssessmentBillManualReviewError::ScopeInvalid,
                AssessmentBillManualReviewError::ChannelInvalid,
                AssessmentBillManualReviewError::ProofInvalid => $this->reviewError(422, 'ASSESSMENT_BILL_REVIEW_INVALID'),
            };
        }

        return $this->privateResponse(response()->json(['data' => ['result' => $result['decision']]]));
    }

    private function proofError(int $status): JsonResponse
    {
        return $this->privateResponse(response()->json([
            'error' => ['code' => 'ASSESSMENT_BILL_PROOF_UNAVAILABLE',
                'message' => 'Bukti pembayaran tidak dapat dibuka.'],
        ], $status));
    }

    private function reviewError(int $status, string $code): JsonResponse
    {
        return $this->privateResponse(response()->json([
            'error' => ['code' => $code, 'message' => 'Keputusan tidak dapat diproses.'],
        ], $status));
    }

    /** @template TResponse of Response
     * @param  TResponse  $response
     * @return TResponse
     */
    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
