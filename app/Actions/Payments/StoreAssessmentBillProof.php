<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentBillProofReceipt;
use App\Data\Payments\AssessmentBillProofUpload;
use App\Enums\AdminRole;
use App\Enums\AssessmentBillProofStorageError;
use App\Exceptions\AssessmentBillProofStorageException;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\Payments\AssessmentPriceSnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use finfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/** Internal private-storage boundary. HTTP, proof access, policy, and UI remain outside P11c2a. */
final readonly class StoreAssessmentBillProof
{
    private const string DISK = 'payment-proofs';

    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentPriceSnapshot $prices,
    ) {}

    public function execute(AssessmentBillProofUpload $upload): AssessmentBillProofReceipt
    {
        if ($this->contexts->current() !== null || DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Assessment bill proof storage requires isolated phases.');
        }

        /** @var array{kind: 'admin'|'participant', actorId: int, organizationId: int,
         *   participantId: int|null, attemptId: int|null} $authority
         */
        $authority = $this->contexts->run(new RlsContext('service'),
            fn (): array => $this->preauthorize($upload->uploader));
        $stored = $this->store($upload);

        try {
            /** @var array{oldKey: string|null, replaced: bool, fingerprint: string} $persisted */
            $persisted = $this->contexts->run(new RlsContext('service'),
                fn (): array => $this->persist($upload, $authority, $stored));
        } catch (Throwable $exception) {
            $this->deleteBestEffort($stored['objectKey']);
            throw $exception;
        }

        if ($persisted['oldKey'] !== null) {
            $this->deleteBestEffort($persisted['oldKey']);
        }

        return new AssessmentBillProofReceipt($persisted['replaced'], $persisted['fingerprint']);
    }

    /** @return array{kind: 'admin'|'participant', actorId: int, organizationId: int,
     *   participantId: int|null, attemptId: int|null}
     */
    private function preauthorize(Admin|AssessmentPrincipal $uploader): array
    {
        if ($uploader instanceof Admin) {
            $id = $uploader->getKey();
            $admin = is_int($id) ? Admin::withTrashed()->find($id) : null;
            if ($admin === null || $admin->deleted_at !== null || $admin->role !== AdminRole::BranchAdmin
                || ! is_int($admin->branch_id)) {
                $this->fail(AssessmentBillProofStorageError::NotFound);
            }

            return ['kind' => 'admin', 'actorId' => $admin->id, 'organizationId' => $admin->branch_id,
                'participantId' => null, 'attemptId' => null];
        }

        $attempt = AssessmentParticipant::query()->where('id', $uploader->assessmentParticipantId)
            ->where('organization_id', $uploader->organizationId)
            ->where('participant_id', $uploader->participantId)->first();
        $participant = Participant::query()->where('id', $uploader->participantId)
            ->where('branch_id', $uploader->organizationId)->first();
        if ($attempt === null || $participant === null) {
            $this->fail(AssessmentBillProofStorageError::NotFound);
        }

        return ['kind' => 'participant', 'actorId' => $participant->id,
            'organizationId' => $uploader->organizationId, 'participantId' => $participant->id,
            'attemptId' => $attempt->id];
    }

    /** @return array{objectKey: string, checksum: string, mimeType: string, size: int, uploadedAt: CarbonImmutable} */
    private function store(AssessmentBillProofUpload $upload): array
    {
        if (! $upload->proof->isValid()) {
            $this->fail(AssessmentBillProofStorageError::ContentInvalid);
        }
        $path = $upload->proof->getRealPath();
        if (! is_string($path)) {
            $this->fail(AssessmentBillProofStorageError::ContentInvalid);
        }
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            $this->fail(AssessmentBillProofStorageError::ContentInvalid);
        }
        $size = strlen($contents);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => null,
        };
        if ($size < 1 || $size > 5_120_000 || $extension === null) {
            $this->fail(AssessmentBillProofStorageError::ContentInvalid);
        }
        $checksum = hash('sha256', $contents);
        if (! preg_match('/^[0-9a-f]{64}$/D', $checksum)) {
            $this->fail(AssessmentBillProofStorageError::ContentInvalid);
        }

        $random = Str::lower(Str::random(64));
        $key = 'assessment-bills/'.substr($random, 0, 2).'/'.substr($random, 2).'.'.$extension;
        if (! preg_match('/^assessment-bills\/[a-z0-9]{2}\/[a-z0-9]{62}\.(jpg|png|pdf)$/D', $key)) {
            throw new RuntimeException('Generated assessment bill proof key is invalid.');
        }
        try {
            $stored = Storage::disk(self::DISK)->put($key, $contents, ['visibility' => 'private']);
        } catch (Throwable) {
            $this->deleteBestEffort($key);
            $this->fail(AssessmentBillProofStorageError::StorageFailed);
        }
        if (! $stored) {
            $this->deleteBestEffort($key);
            $this->fail(AssessmentBillProofStorageError::StorageFailed);
        }

        return ['objectKey' => $key, 'checksum' => $checksum, 'mimeType' => $mime, 'size' => $size,
            'uploadedAt' => now()->toImmutable()->utc()->startOfSecond()];
    }

    /**
     * @param array{kind: 'admin'|'participant', actorId: int, organizationId: int,
     *   participantId: int|null, attemptId: int|null} $authority
     * @param  array{objectKey: string, checksum: string, mimeType: string, size: int, uploadedAt: CarbonImmutable}  $stored
     * @return array{oldKey: string|null, replaced: bool, fingerprint: string}
     */
    private function persist(AssessmentBillProofUpload $upload, array $authority, array $stored): array
    {
        if ($authority['kind'] === 'admin') {
            $admin = Admin::withTrashed()->lockForUpdate()->find($authority['actorId']);
            if ($admin === null || $admin->deleted_at !== null || $admin->role !== AdminRole::BranchAdmin
                || $admin->branch_id !== $authority['organizationId']) {
                $this->fail(AssessmentBillProofStorageError::NotFound);
            }
        }
        if (Branch::query()->lockForUpdate()->find($authority['organizationId']) === null) {
            $this->fail(AssessmentBillProofStorageError::NotFound);
        }
        $bill = AssessmentBill::query()->where('organization_id', $authority['organizationId'])
            ->where('public_reference', $upload->billReference)->lockForUpdate()->first();
        if ($bill === null) {
            $this->fail(AssessmentBillProofStorageError::NotFound);
        }
        ['items' => $items, 'attempts' => $attempts, 'participants' => $participants,
            'packages' => $packages, 'charges' => $charges, 'method' => $method] = $this->lockAllocations($bill);
        $this->assertCanonical($bill, $items, $attempts, $participants, $packages, $charges, $method);
        $this->assertUploaderOwnsBill($authority, $bill, $attempts);
        $currentFingerprint = $this->currentFingerprint($bill);
        if (($currentFingerprint === null) !== ($upload->expectedCurrentProofFingerprint === null)
            || ($currentFingerprint !== null
                && ! hash_equals($currentFingerprint, (string) $upload->expectedCurrentProofFingerprint))) {
            $this->fail(AssessmentBillProofStorageError::Conflict);
        }

        $oldKey = $bill->proof_object_key;
        $fingerprint = $this->fingerprint($stored['objectKey'], $stored['checksum'], $stored['mimeType'],
            $stored['size'], $stored['uploadedAt']);
        $bill->update([
            'proof_object_key' => $stored['objectKey'],
            'proof_checksum_sha256' => $stored['checksum'],
            'proof_mime_type' => $stored['mimeType'],
            'proof_size_bytes' => $stored['size'],
            'proof_uploaded_at' => $stored['uploadedAt'],
        ]);

        return ['oldKey' => $oldKey, 'replaced' => $oldKey !== null, 'fingerprint' => $fingerprint];
    }

    /** @return array{items: Collection<int, AssessmentBillItem>, attempts: Collection<int, AssessmentParticipant>,
     *   participants: Collection<int, Participant>, packages: Collection<int, TestPackage>,
     *   charges: Collection<int, AssessmentCharge>, method: PaymentMethod|null}
     */
    private function lockAllocations(AssessmentBill $bill): array
    {
        $items = AssessmentBillItem::query()->where('bill_id', $bill->id)->orderBy('id')->lockForUpdate()->get();
        $chargeHints = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))
            ->get(['id', 'assessment_participant_id']);
        $attempts = AssessmentParticipant::query()->where('organization_id', $bill->organization_id)
            ->whereIn('id', $chargeHints->pluck('assessment_participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $participants = Participant::query()->where('branch_id', $bill->organization_id)
            ->whereIn('id', $attempts->pluck('participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $packages = TestPackage::query()->whereIn('id', $attempts->pluck('package_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $charges = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))
            ->orderBy('assessment_participant_id')->lockForUpdate()->get()->keyBy('id');
        $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);

        return compact('items', 'attempts', 'participants', 'packages', 'charges', 'method');
    }

    /** @param Collection<int, AssessmentBillItem> $items
     * @param  Collection<int, AssessmentParticipant>  $attempts
     * @param  Collection<int, Participant>  $participants
     * @param  Collection<int, TestPackage>  $packages
     * @param  Collection<int, AssessmentCharge>  $charges
     */
    private function assertCanonical(AssessmentBill $bill, Collection $items, Collection $attempts,
        Collection $participants, Collection $packages, Collection $charges, ?PaymentMethod $method): void
    {
        if ($method === null || $method->code !== 'manual_transfer' || $bill->gateway_ref !== null
            || $bill->invoice_url !== null) {
            $this->fail(AssessmentBillProofStorageError::ChannelInvalid);
        }
        if ($bill->status !== 'pending' || $bill->paid_at !== null || $bill->verified_at !== null
            || $bill->verified_by_admin_id !== null || $bill->rejection_reason !== null) {
            $this->fail(AssessmentBillProofStorageError::StateInvalid);
        }
        if ($bill->amount < 1 || $bill->currency !== 'IDR' || $bill->item_count < 1
            || $items->count() !== $bill->item_count || ! in_array($bill->payer_type, ['self', 'organization'], true)
            || ($bill->payer_type === 'organization' ? $bill->payer_participant_id !== null
                : ($bill->payer_participant_id === null || $bill->item_count !== 1))) {
            $this->fail(AssessmentBillProofStorageError::ScopeInvalid);
        }

        $total = 0;
        foreach ($items as $item) {
            $charge = $charges->get($item->charge_id);
            $attempt = $charge === null ? null : $attempts->get($charge->assessment_participant_id);
            $participant = $attempt === null ? null : $participants->get($attempt->participant_id);
            $package = $attempt === null ? null : $packages->get($attempt->package_id);
            $funding = $bill->payer_type === 'self' ? 'COMMERCIAL_SELF_PAY' : 'INVOICED_TO_ORGANIZATION';
            $metadata = $attempt?->metadata;
            if ($charge === null || $attempt === null || $participant === null || $package === null
                || $item->settled_at !== null || $item->bill_id !== $bill->id
                || $item->organization_id !== $bill->organization_id || $item->participant_id !== $participant->id
                || $item->payer_type !== $bill->payer_type || $item->payer_participant_id !== $bill->payer_participant_id
                || $item->amount !== $charge->amount || $item->currency !== $bill->currency
                || $charge->organization_id !== $bill->organization_id || $charge->participant_id !== $participant->id
                || $charge->package_id !== $package->id || $charge->payer_type !== $bill->payer_type
                || $charge->currency !== $bill->currency || $attempt->organization_id !== $bill->organization_id
                || $attempt->participant_id !== $participant->id || $attempt->package_id !== $package->id
                || $attempt->funding_mode !== $funding || ! is_array($metadata)
                || ($metadata['checkout_contract_version'] ?? null) !== 'checkout-v2'
                || ! array_key_exists('checkout_initial_funding_mode', $metadata)
                || ! in_array($metadata['checkout_initial_funding_mode'], [null, $funding], true)) {
                $this->fail(AssessmentBillProofStorageError::ScopeInvalid);
            }
            try {
                $this->prices->fromCharge($charge, $charge->consultation_requested);
            } catch (DomainException) {
                $this->fail(AssessmentBillProofStorageError::ScopeInvalid);
            }
            if ($item->amount < 1 || $total > PHP_INT_MAX - $item->amount) {
                $this->fail(AssessmentBillProofStorageError::ScopeInvalid);
            }
            $total += $item->amount;
        }
        if ($total !== $bill->amount) {
            $this->fail(AssessmentBillProofStorageError::ScopeInvalid);
        }
    }

    /** @param array{kind: 'admin'|'participant', actorId: int, organizationId: int,
     *   participantId: int|null, attemptId: int|null} $authority
     * @param  Collection<int, AssessmentParticipant>  $attempts
     */
    private function assertUploaderOwnsBill(array $authority, AssessmentBill $bill, Collection $attempts): void
    {
        if ($authority['kind'] === 'admin') {
            if ($bill->payer_type !== 'organization') {
                $this->fail(AssessmentBillProofStorageError::NotFound);
            }

            return;
        }
        $attempt = $authority['attemptId'] === null ? null : $attempts->get($authority['attemptId']);
        if ($bill->payer_type !== 'self' || $bill->payer_participant_id !== $authority['participantId']
            || $attempt === null || $attempt->participant_id !== $authority['participantId']
            || $attempt->organization_id !== $authority['organizationId'] || $attempts->count() !== 1) {
            $this->fail(AssessmentBillProofStorageError::NotFound);
        }
    }

    private function currentFingerprint(AssessmentBill $bill): ?string
    {
        $values = [$bill->proof_object_key, $bill->proof_checksum_sha256, $bill->proof_mime_type,
            $bill->proof_size_bytes, $bill->proof_uploaded_at];
        if (array_filter($values, static fn (mixed $value): bool => $value !== null) === []) {
            return null;
        }
        if (! is_string($bill->proof_object_key) || ! is_string($bill->proof_checksum_sha256)
            || ! is_string($bill->proof_mime_type) || ! is_int($bill->proof_size_bytes)
            || $bill->proof_uploaded_at === null) {
            $this->fail(AssessmentBillProofStorageError::ScopeInvalid);
        }

        return $this->fingerprint($bill->proof_object_key, $bill->proof_checksum_sha256, $bill->proof_mime_type,
            $bill->proof_size_bytes, CarbonImmutable::instance($bill->proof_uploaded_at));
    }

    private function fingerprint(string $key, string $checksum, string $mime, int $size,
        CarbonImmutable $uploadedAt): string
    {
        return hash('sha256', implode("\0", [
            $key, $checksum, $mime, (string) $size, $uploadedAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ]));
    }

    private function deleteBestEffort(string $key): void
    {
        try {
            Storage::disk(self::DISK)->delete($key);
        } catch (Throwable) {
            // A failed cleanup must not hide the authoritative transaction result.
        }
    }

    private function fail(AssessmentBillProofStorageError $error): never
    {
        throw new AssessmentBillProofStorageException($error);
    }
}
