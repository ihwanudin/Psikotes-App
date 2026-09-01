<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\AssessmentBillProofAccess;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

final readonly class OrganizationBillProofUrlIssuer
{
    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentBillProofIdentity $proofs,
    ) {}

    public function issue(Admin $actor, string $billReference, string $expectedFingerprint): AssessmentBillProofAccess
    {
        if (! app()->environment('testing') || $this->contexts->current() !== null
            || DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Organization proof access is available only from an isolated testing boundary.');
        }
        if (! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $billReference)
            || ! preg_match('/^[0-9a-f]{64}$/D', $expectedFingerprint)) {
            $this->notFound();
        }

        $loaded = $this->contexts->run(new RlsContext('service'),
            fn (): array => $this->load($actor->getKey(), $billReference, $expectedFingerprint));
        $expiresAt = CarbonImmutable::now()->addMinutes(15);

        try {
            $disk = Storage::disk('payment-proofs');
            if (! $disk->exists($loaded['key'])) {
                $this->notFound();
            }
            $url = $disk->temporaryUrl($loaded['key'], $expiresAt);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DomainException('ORGANIZATION_BILL_PROOF_UNAVAILABLE');
        }

        $this->contexts->run(new RlsContext('service'), function () use (
            $actor,
            $billReference,
            $expectedFingerprint,
            $expiresAt,
        ): void {
            DB::transaction(function () use ($actor, $billReference, $expectedFingerprint, $expiresAt): void {
                $loaded = $this->load($actor->getKey(), $billReference, $expectedFingerprint, lock: true);
                DB::table('audit_logs')->insert([
                    'branch_id' => $loaded['organizationId'],
                    'actor_type' => 'admin',
                    'actor_id' => (string) $loaded['actorId'],
                    'action' => 'assessment_bill.branch_proof_temporary_url_issued',
                    'subject_type' => AssessmentBill::class,
                    'subject_id' => (string) $loaded['billId'],
                    'context' => json_encode([
                        'version' => 1,
                        'proof_fingerprint' => $expectedFingerprint,
                        'url_expires_at' => $expiresAt->toIso8601String(),
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(),
                    'expires_at' => now()->addYears(2),
                ]);
            });
        });

        return new AssessmentBillProofAccess($url, $expiresAt, $expectedFingerprint);
    }

    /** @return array{key: string, actorId: int, billId: int, organizationId: int} */
    private function load(mixed $actorId, string $reference, string $expectedFingerprint, bool $lock = false): array
    {
        $adminQuery = Admin::withTrashed();
        $billQuery = AssessmentBill::query()->with('paymentMethod');
        if ($lock) {
            $adminQuery->lockForUpdate();
            $billQuery->lockForUpdate();
        }
        $admin = is_int($actorId) ? $adminQuery->find($actorId) : null;
        if ($admin === null || $admin->deleted_at !== null || $admin->role !== AdminRole::BranchAdmin
            || ! is_int($admin->branch_id)) {
            $this->notFound();
        }
        $bill = $billQuery->where('public_reference', $reference)
            ->where('organization_id', $admin->branch_id)->where('payer_type', 'organization')->first();
        if ($bill === null || $bill->paymentMethod?->code !== 'manual_transfer'
            || ! is_string($bill->proof_object_key)) {
            $this->notFound();
        }
        try {
            $fingerprint = $this->proofs->fingerprint($bill, now());
        } catch (DomainException) {
            $this->notFound();
        }
        if (! is_string($fingerprint) || ! hash_equals($fingerprint, $expectedFingerprint)) {
            $this->notFound();
        }

        return ['key' => $bill->proof_object_key, 'actorId' => $admin->id,
            'billId' => $bill->id, 'organizationId' => $admin->branch_id];
    }

    private function notFound(): never
    {
        throw new DomainException('ORGANIZATION_BILL_PROOF_NOT_FOUND');
    }
}
