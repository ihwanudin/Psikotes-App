<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\SelectionParticipant;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutContractAdapter;
use App\Services\TestNumber\MonthlyTestNumberIssuer;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ProvisionSelectionParticipant
{
    private const array INTENDED_FIELDS = ['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'];

    public function __construct(
        private RlsContextRunner $runner,
        private MonthlyTestNumberIssuer $testNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{participant_id: int, replayed: bool}
     */
    public function handle(array $input, string $clientId, string $idempotencyKey): array
    {
        $requestHash = $this->requestHash($input);

        try {
            return $this->runner->run(
                new RlsContext('service'),
                fn (): array => $this->createOnce($input, $clientId, $idempotencyKey, $requestHash),
            );
        } catch (QueryException $exception) {
            $existing = $this->findExisting(
                $clientId,
                $idempotencyKey,
                (string) $input['externalCandidateId'],
            );
            if ($existing === null) {
                throw $exception;
            }

            return $this->runner->run(
                new RlsContext('service'),
                fn (): array => $this->replayResult($existing, $requestHash),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{participant_id: int, replayed: bool}
     */
    private function createOnce(array $input, string $clientId, string $idempotencyKey, string $requestHash): array
    {
        // Check before idempotent replay; the dedicated client is mapped by server branch_ref.
        $organizationId = Branch::query()->where('ref_code', config('selection_integration.branch_ref'))->value('id');
        if ($organizationId !== null) {
            try {
                app(CheckoutContractAdapter::class)->assertLegacyAllowed((int) $organizationId, 'SELEKSI_BEASISWA_JEPANG');
            } catch (IntegrationContractViolation) {
                throw new SelectionIntegrationUnavailable;
            }
        }
        $existing = $this->findReplay($clientId, $idempotencyKey, (string) $input['externalCandidateId'], true);

        if ($existing !== null) {
            return $this->replayResult($existing, $requestHash);
        }

        $branchRef = config('selection_integration.branch_ref');
        $branch = is_string($branchRef)
            ? Branch::query()->where('ref_code', $branchRef)->where('is_active', true)->sharedLock()->first()
            : null;
        $intendedField = strtoupper((string) config('selection_integration.intended_field', 'UMUM'));
        $configuredTestTypes = config('selection_integration.test_types', ['ist']);

        if ($branch === null
            || ! in_array($intendedField, self::INTENDED_FIELDS, true)
            || ! is_array($configuredTestTypes)
            || ! array_is_list($configuredTestTypes)
            || $configuredTestTypes === []
            || in_array('dass21', $configuredTestTypes, true)) {
            throw new SelectionIntegrationUnavailable;
        }
        try {
            $testTypes = TestPackage::canonicalComposition([...$configuredTestTypes, 'dass21']);
        } catch (DomainException) {
            throw new SelectionIntegrationUnavailable;
        }

        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'manual',
            'source_system' => 'SELEKSI_BEASISWA_JEPANG',
            'attribution_source' => $branch->ref_code,
            'full_name' => $input['fullName'],
            'gender' => $input['gender'],
            'birth_date' => $input['birthDate'],
            'education_level' => $input['educationLevel'],
            'intended_field' => $intendedField,
            'phone' => preg_replace('/[^0-9+]/', '', (string) $input['phone']),
            'email' => strtolower((string) $input['email']),
            'test_number' => $this->testNumbers->issue(),
        ]);

        $now = now();
        $participant->entitlements()->createMany(array_map(
            static fn (string $testType): array => [
                'order_id' => null,
                'test_type' => $testType,
                'status' => 'ready',
                'ready_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $testTypes,
        ));

        $case = AssessmentCase::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => null,
            'origin' => 'LEGACY_SELECTION',
            'intended_field_snapshot' => $intendedField,
        ]);

        SelectionParticipant::query()->create([
            'client_id' => $clientId,
            'external_candidate_id' => $input['externalCandidateId'],
            'selection_round_id' => $input['selectionRoundId'],
            'registration_id' => $input['registrationId'],
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
        ]);

        DB::table('audit_logs')->insert([
            'branch_id' => $branch->id,
            'actor_type' => 'service',
            'actor_id' => $clientId,
            'action' => 'selection_participant.provisioned',
            'subject_type' => Participant::class,
            'subject_id' => (string) $participant->id,
            'context' => json_encode([
                'selection_round_id' => $input['selectionRoundId'],
                'test_types' => $testTypes,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'expires_at' => $now->addYears(5),
        ]);

        return ['participant_id' => $participant->id, 'replayed' => false];
    }

    private function findExisting(
        string $clientId,
        string $idempotencyKey,
        string $externalCandidateId,
    ): ?SelectionParticipant {
        return $this->runner->run(
            new RlsContext('service'),
            fn (): ?SelectionParticipant => $this->findReplay($clientId, $idempotencyKey, $externalCandidateId, true),
        );
    }

    private function findReplay(
        string $clientId,
        string $idempotencyKey,
        string $externalCandidateId,
        bool $lock,
    ): ?SelectionParticipant {
        $query = SelectionParticipant::query()
            ->where('client_id', $clientId)
            ->where(function ($query) use ($idempotencyKey, $externalCandidateId): void {
                $query->where('idempotency_key', $idempotencyKey)
                    ->orWhere('external_candidate_id', $externalCandidateId);
            })
            ->orderBy('id')
            ->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();
        if ($matches->count() > 1) {
            throw new IdempotencyConflict;
        }

        return $matches->first();
    }

    /** @return array{participant_id:int,replayed:bool} */
    private function replayResult(SelectionParticipant $existing, string $requestHash): array
    {
        $this->assertSameRequest($existing, $requestHash);
        $case = $existing->assessmentCase()->first();
        $organizationId = $existing->participant()->value('branch_id');
        if ($case === null
            || $case->participant_id !== $existing->participant_id
            || $case->organization_id !== $organizationId
            || $case->origin !== 'LEGACY_SELECTION') {
            throw new IdempotencyConflict;
        }

        return ['participant_id' => $existing->participant_id, 'replayed' => true];
    }

    private function assertSameRequest(SelectionParticipant $existing, string $requestHash): void
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }
    }

    /** @param array<string, mixed> $input */
    private function requestHash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
