<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\AssessmentBillProofAccess;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

/** Internal proof-access issuer. HTTP redirects and reviewer UI remain outside P11c2b. */
final readonly class AssessmentBillProofUrlIssuer
{
    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentBillProofIdentity $proofs,
    ) {}

    public function issue(Admin $actor, string $billReference): AssessmentBillProofAccess
    {
        if ($this->contexts->current() !== null || DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Assessment bill proof access requires isolated phases.');
        }
        if (! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $billReference)) {
            $this->notFound();
        }
        $diskName = config('payments.manual_proof_disk', 'payment-proofs');
        $minutes = config('payments.manual_proof_temporary_url_minutes', 15);
        if (! is_string($diskName) || $diskName !== 'payment-proofs'
            || ! is_int($minutes) || $minutes < 1 || $minutes > 60) {
            throw new LogicException('Assessment bill proof access configuration is invalid.');
        }
        $expiresAt = now()->toImmutable()->utc()->addMinutes($minutes);

        /** @var array{billId: int, organizationId: int, objectKey: string, fingerprint: string} $snapshot */
        $snapshot = $this->contexts->run(new RlsContext('service'),
            fn (): array => $this->lockSnapshot($actor, $billReference));

        try {
            $disk = Storage::disk($diskName);
            $exists = $disk->exists($snapshot['objectKey']);
        } catch (Throwable) {
            $this->unavailable();
        }
        if (! $exists) {
            $this->notFound();
        }
        try {
            $url = $disk->temporaryUrl($snapshot['objectKey'], $expiresAt);
        } catch (Throwable) {
            $this->unavailable();
        }
        if ($url === '') {
            $this->unavailable();
        }

        $this->contexts->run(new RlsContext('service'), function () use (
            $actor, $billReference, $snapshot, $expiresAt,
        ): void {
            $reviewer = $this->lockReviewer($actor);
            if (Branch::query()->lockForUpdate()->find($snapshot['organizationId']) === null) {
                $this->notFound();
            }
            $bill = AssessmentBill::query()->where('organization_id', $snapshot['organizationId'])
                ->where('public_reference', $billReference)->lockForUpdate()->first();
            if ($bill === null || $bill->id !== $snapshot['billId']) {
                $this->notFound();
            }
            $fingerprint = $this->canonicalFingerprint($bill);
            if (! hash_equals($snapshot['fingerprint'], $fingerprint)) {
                $this->notFound();
            }
            $now = now()->toImmutable()->utc();
            DB::table('audit_logs')->insert([
                'branch_id' => $snapshot['organizationId'],
                'actor_type' => 'admin',
                'actor_id' => (string) $reviewer->id,
                'action' => 'assessment_bill.proof_temporary_url_issued',
                'subject_type' => AssessmentBill::class,
                'subject_id' => (string) $bill->id,
                'context' => json_encode([
                    'version' => 1,
                    'source' => 'assessment_bill_manual_review',
                    'proof_fingerprint' => $fingerprint,
                    'url_expires_at' => $expiresAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'expires_at' => $now->addYearsNoOverflow(2),
            ]);
        });

        return new AssessmentBillProofAccess($url, $expiresAt, $snapshot['fingerprint']);
    }

    /** @return array{billId: int, organizationId: int, objectKey: string, fingerprint: string} */
    private function lockSnapshot(Admin $actor, string $billReference): array
    {
        $this->lockReviewer($actor);
        $hint = AssessmentBill::query()->where('public_reference', $billReference)->first(['organization_id']);
        if ($hint === null || Branch::query()->lockForUpdate()->find($hint->organization_id) === null) {
            $this->notFound();
        }
        $bill = AssessmentBill::query()->where('organization_id', $hint->organization_id)
            ->where('public_reference', $billReference)->lockForUpdate()->first();
        if ($bill === null) {
            $this->notFound();
        }
        $fingerprint = $this->canonicalFingerprint($bill);

        return ['billId' => $bill->id, 'organizationId' => $bill->organization_id,
            'objectKey' => (string) $bill->proof_object_key, 'fingerprint' => $fingerprint];
    }

    private function lockReviewer(Admin $actor): Admin
    {
        $id = $actor->getKey();
        $reviewer = is_int($id) ? Admin::withTrashed()->lockForUpdate()->find($id) : null;
        if ($reviewer === null || $reviewer->deleted_at !== null || $reviewer->role !== AdminRole::SuperAdmin) {
            $this->notFound();
        }

        return $reviewer;
    }

    private function canonicalFingerprint(AssessmentBill $bill): string
    {
        $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);
        if ($method === null || $method->code !== 'manual_transfer'
            || $bill->gateway_ref !== null || $bill->invoice_url !== null) {
            $this->notFound();
        }
        try {
            $fingerprint = $this->proofs->fingerprint($bill, now());
        } catch (DomainException) {
            $this->notFound();
        }
        if ($fingerprint === null) {
            $this->notFound();
        }

        return $fingerprint;
    }

    private function notFound(): never
    {
        throw new DomainException('ASSESSMENT_BILL_PROOF_ACCESS_NOT_FOUND');
    }

    private function unavailable(): never
    {
        throw new DomainException('ASSESSMENT_BILL_PROOF_ACCESS_UNAVAILABLE');
    }
}
