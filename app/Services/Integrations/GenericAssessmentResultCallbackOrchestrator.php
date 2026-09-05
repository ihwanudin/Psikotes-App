<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Jobs\DispatchGenericAssessmentResultCallback;
use App\Models\GenericAssessmentResultVersion;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class GenericAssessmentResultCallbackOrchestrator
{
    private const int MAX_ITEMS = 100;

    private const int MAX_ATTEMPTS = 4;

    private const int RECOVERY_SECONDS = 300;

    public function __construct(
        private GenericAssessmentResultCallbackDispatcher $callback,
        private RlsContextRunner $runner,
    ) {}

    /** @return array{selected:int,queued:int,brokerFailures:int} */
    public function schedule(int $limit): array
    {
        if ($limit < 1 || $limit > self::MAX_ITEMS) {
            throw new InvalidArgumentException('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_LIMIT_INVALID');
        }

        $schedules = $this->runner->run(new RlsContext('service'), function () use ($limit): array {
            $rows = $this->eligibleQuery()->limit($limit)->get();
            $prepared = [];
            foreach ($rows as $row) {
                $schedule = $this->prepareSchedule(GenericAssessmentResultCallbackScheduleBinding::fromRow($row));
                if ($schedule !== null) {
                    $prepared[] = $schedule;
                }
            }

            return $prepared;
        });

        $queued = 0;
        $brokerFailures = 0;
        foreach ($schedules as $schedule) {
            try {
                $pending = DispatchGenericAssessmentResultCallback::dispatch((string) $schedule['scheduleId']);
                unset($pending);
                $this->markBrokerAccepted((string) $schedule['scheduleId']);
                $queued++;
            } catch (Throwable) {
                $this->markBrokerFailed((string) $schedule['scheduleId']);
                $brokerFailures++;
            }
        }

        return ['selected' => count($schedules), 'queued' => $queued, 'brokerFailures' => $brokerFailures];
    }

    /** @return array<string,mixed> */
    public function execute(string $scheduleId): array
    {
        if (! Str::isUlid($scheduleId)) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_INVALID');
        }

        $binding = $this->beginExecution($scheduleId);
        if ($binding === null) {
            return ['action' => 'SKIPPED_SCHEDULE_STATE'];
        }

        try {
            $result = $this->callback->dispatchExact(
                $binding->outboxId,
                $binding->sourceId,
                $binding->resultVersion,
                $binding->resultChecksum,
                bin2hex(random_bytes(32)),
            );
        } catch (Throwable $exception) {
            $this->recordWorkerFailure($scheduleId);
            throw $exception;
        }

        $this->completeExecution($scheduleId, $result);

        return $result;
    }

    public function recordWorkerFailure(string $scheduleId): void
    {
        if (! Str::isUlid($scheduleId)) {
            return;
        }

        $this->runner->run(new RlsContext('service'), function () use ($scheduleId): void {
            $row = $this->scheduleBinding($scheduleId, true);
            if ($row === null || ! in_array($row->state, ['PENDING', 'QUEUED', 'RUNNING'], true)) {
                return;
            }
            $now = CarbonImmutable::instance(now())->utc();
            DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                'state' => 'WORKER_FAILED', 'next_dispatch_at' => $now->addSeconds(self::RECOVERY_SECONDS),
                'completed_at' => null, 'last_failure_code' => 'WORKER_EXECUTION_FAILED', 'updated_at' => $now,
            ]);
            $this->audit($row, 'worker_failed', 'WORKER_EXECUTION_FAILED');
        });
    }

    private function eligibleQuery(): Builder
    {
        $now = CarbonImmutable::instance(now())->utc();
        $latestAttempts = DB::table('generic_assessment_result_dispatch_attempts')
            ->select('outbox_id')->selectRaw('MAX(attempt_number) AS attempt_number')->groupBy('outbox_id');

        return DB::table('generic_assessment_result_outbox as o')
            ->join('generic_assessment_result_versions as r', 'r.id', '=', 'o.generic_assessment_result_version_id')
            ->join('assessment_participants as p', 'p.id', '=', 'o.assessment_participant_id')
            ->join('integration_clients as c', 'c.id', '=', 'p.integration_client_id')
            ->leftJoinSub($latestAttempts, 'la', 'la.outbox_id', '=', 'o.id')
            ->leftJoin('generic_assessment_result_dispatch_attempts as a', function ($join): void {
                $join->on('a.outbox_id', '=', 'o.id')->on('a.attempt_number', '=', 'la.attempt_number');
            })
            ->leftJoin('generic_assessment_result_callback_schedules as s', 's.outbox_id', '=', 'o.id')
            ->where('c.enabled', true)
            ->whereIn('c.result_delivery_mode', ['CALLBACK', 'CALLBACK_AND_POLL'])
            ->where(fn (Builder $query): Builder => $query->whereNull('c.effective_from')->orWhere('c.effective_from', '<=', $now))
            ->where(fn (Builder $query): Builder => $query->whereNull('c.effective_until')->orWhere('c.effective_until', '>', $now))
            ->where('r.result_version', '=', function (Builder $query): void {
                $query->from('generic_assessment_result_versions as newest')->selectRaw('MAX(newest.result_version)')
                    ->whereColumn('newest.assessment_participant_id', 'r.assessment_participant_id')
                    ->whereColumn('newest.assessment_attempt_id', 'r.assessment_attempt_id');
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('la.outbox_id')
                    ->orWhere(fn (Builder $active): Builder => $active->where('a.outcome', 'PROCESSING')->where('a.lease_expires_at', '<=', $now))
                    ->orWhere(fn (Builder $retry): Builder => $retry->where('a.outcome', 'RETRYABLE')
                        ->where('a.attempt_number', '<', self::MAX_ATTEMPTS)
                        ->whereNotNull('a.next_attempt_at')->where('a.next_attempt_at', '<=', $now));
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('s.id')->orWhere(function (Builder $due) use ($now): void {
                    $due->where('s.state', '<>', 'BROKER_EXHAUSTED')->where('s.next_dispatch_at', '<=', $now);
                });
            })
            ->orderBy('o.created_at')->orderBy('o.id')
            ->select(['o.id as outbox_id', 'r.id as source_id', 'r.result_version', 'r.result_checksum', 'p.organization_id']);
    }

    /** @return array{scheduleId:string}|null */
    private function prepareSchedule(GenericAssessmentResultCallbackScheduleBinding $binding): ?array
    {
        $now = CarbonImmutable::instance(now())->utc();
        $id = (string) Str::ulid();
        $inserted = DB::table('generic_assessment_result_callback_schedules')->insertOrIgnore([
            'id' => $id, 'outbox_id' => $binding->outboxId, 'state' => 'PENDING',
            'broker_attempts' => 1, 'next_dispatch_at' => $now->addSeconds(self::RECOVERY_SECONDS),
            'queued_at' => null, 'completed_at' => null, 'last_failure_code' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $scheduleId = DB::table('generic_assessment_result_callback_schedules')
            ->where('outbox_id', $binding->outboxId)->lockForUpdate()->value('id');
        if (! is_string($scheduleId)) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_PERSISTENCE_FAILED');
        }
        $schedule = $this->scheduleBinding($scheduleId, true);
        if ($schedule === null) {
            throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_PERSISTENCE_FAILED');
        }

        if ($inserted === 0) {
            if ($schedule->state === 'BROKER_EXHAUSTED'
                || $schedule->nextDispatchAt === null
                || CarbonImmutable::parse($schedule->nextDispatchAt)->utc()->gt($now)) {
                return null;
            }
            if (($schedule->brokerAttempts ?? 0) >= self::MAX_ATTEMPTS) {
                DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                    'state' => 'BROKER_EXHAUSTED', 'next_dispatch_at' => null,
                    'last_failure_code' => 'BROKER_DISPATCH_FAILED', 'updated_at' => $now,
                ]);
                $this->audit($schedule, 'broker_exhausted', 'BROKER_DISPATCH_FAILED');

                return null;
            }
            DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                'state' => 'PENDING', 'broker_attempts' => ($schedule->brokerAttempts ?? 0) + 1,
                'next_dispatch_at' => $now->addSeconds(self::RECOVERY_SECONDS),
                'queued_at' => null, 'completed_at' => null, 'last_failure_code' => null, 'updated_at' => $now,
            ]);
        }

        $this->audit($binding, 'scheduled', null);

        return ['scheduleId' => $scheduleId];
    }

    private function markBrokerAccepted(string $scheduleId): void
    {
        $this->runner->run(new RlsContext('service'), function () use ($scheduleId): void {
            $row = $this->scheduleBinding($scheduleId, true);
            if ($row === null || $row->state !== 'PENDING') {
                return;
            }
            $now = CarbonImmutable::instance(now())->utc();
            DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                'state' => 'QUEUED', 'queued_at' => $now,
                'next_dispatch_at' => $now->addSeconds(self::RECOVERY_SECONDS), 'updated_at' => $now,
            ]);
            $this->audit($row, 'broker_accepted', null);
        });
    }

    private function markBrokerFailed(string $scheduleId): void
    {
        $this->runner->run(new RlsContext('service'), function () use ($scheduleId): void {
            $row = $this->scheduleBinding($scheduleId, true);
            if ($row === null || $row->state !== 'PENDING') {
                return;
            }
            $now = CarbonImmutable::instance(now())->utc();
            $exhausted = ($row->brokerAttempts ?? 0) >= self::MAX_ATTEMPTS;
            DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                'state' => $exhausted ? 'BROKER_EXHAUSTED' : 'BROKER_FAILED',
                'next_dispatch_at' => $exhausted ? null : $now->addSeconds(self::RECOVERY_SECONDS),
                'last_failure_code' => 'BROKER_DISPATCH_FAILED', 'updated_at' => $now,
            ]);
            $this->audit($row, $exhausted ? 'broker_exhausted' : 'broker_failed', 'BROKER_DISPATCH_FAILED');
        });
    }

    private function beginExecution(string $scheduleId): ?GenericAssessmentResultCallbackScheduleBinding
    {
        return $this->runner->run(new RlsContext('service'), function () use ($scheduleId): ?GenericAssessmentResultCallbackScheduleBinding {
            $row = $this->scheduleBinding($scheduleId, true);
            if ($row === null || ! in_array($row->state, ['PENDING', 'QUEUED', 'BROKER_FAILED'], true)) {
                if ($row !== null) {
                    $this->audit($row, 'skipped', 'SCHEDULE_STATE_INELIGIBLE');
                }

                return null;
            }
            $now = CarbonImmutable::instance(now())->utc();
            if ($row->state === 'PENDING' && $row->nextDispatchAt !== null
                && CarbonImmutable::parse($row->nextDispatchAt)->utc()->gt($now)) {
                $this->audit($row, 'skipped', 'SCHEDULE_NOT_DUE');

                return null;
            }
            DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                'state' => 'RUNNING', 'next_dispatch_at' => $now->addSeconds(self::RECOVERY_SECONDS),
                'last_failure_code' => null, 'updated_at' => $now,
            ]);
            $this->audit($row, 'worker_started', null);

            return $row;
        });
    }

    /** @param array<string,mixed> $result */
    private function completeExecution(string $scheduleId, array $result): void
    {
        $this->runner->run(new RlsContext('service'), function () use ($scheduleId, $result): void {
            $row = $this->scheduleBinding($scheduleId, true);
            if ($row === null || $row->state !== 'RUNNING') {
                throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_STATE_CONFLICT');
            }
            $now = CarbonImmutable::instance(now())->utc();
            $retryAt = ($result['outcome'] ?? null) === 'RETRYABLE' && is_string($result['nextAttemptAt'] ?? null)
                ? CarbonImmutable::parse($result['nextAttemptAt'])->utc()
                : null;
            DB::table('generic_assessment_result_callback_schedules')->where('id', $scheduleId)->update([
                'state' => $retryAt === null ? 'COMPLETED' : 'PENDING',
                'next_dispatch_at' => $retryAt, 'completed_at' => $retryAt === null ? $now : null,
                'queued_at' => null,
                'last_failure_code' => null, 'updated_at' => $now,
            ]);
            $this->audit($row, 'worker_completed', null, [
                'dispatchAction' => is_string($result['action'] ?? null) ? $result['action'] : null,
                'outcome' => is_string($result['outcome'] ?? null) ? $result['outcome'] : null,
            ]);
        });
    }

    private function scheduleBinding(string $scheduleId, bool $lock): ?GenericAssessmentResultCallbackScheduleBinding
    {
        $query = DB::table('generic_assessment_result_callback_schedules as s')
            ->join('generic_assessment_result_outbox as o', 'o.id', '=', 's.outbox_id')
            ->join('generic_assessment_result_versions as r', 'r.id', '=', 'o.generic_assessment_result_version_id')
            ->join('assessment_participants as p', 'p.id', '=', 'o.assessment_participant_id')
            ->where('s.id', $scheduleId)
            ->select(['s.*', 'o.id as outbox_id', 'r.id as source_id', 'r.result_version', 'r.result_checksum', 'p.organization_id']);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        return $row === null ? null : GenericAssessmentResultCallbackScheduleBinding::fromRow($row);
    }

    /** @param array<string,string|null> $extra */
    private function audit(GenericAssessmentResultCallbackScheduleBinding $binding, string $action, ?string $reasonCode, array $extra = []): void
    {
        $at = CarbonImmutable::instance(now())->utc();
        DB::table('audit_logs')->insert([
            'branch_id' => $binding->organizationId, 'actor_type' => 'system', 'actor_id' => null,
            'action' => 'generic_assessment_result_callback.'.$action,
            'subject_type' => GenericAssessmentResultVersion::class, 'subject_id' => $binding->outboxId,
            'context' => json_encode([
                'resultVersion' => $binding->resultVersion,
                'resultChecksum' => $binding->resultChecksum,
                'brokerAttempts' => $binding->brokerAttempts ?? 1,
                'reasonCode' => $reasonCode,
                ...$extra,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $at, 'expires_at' => $at->addYearsNoOverflow(2),
        ]);
    }
}

final readonly class GenericAssessmentResultCallbackScheduleBinding
{
    public function __construct(
        public string $outboxId,
        public string $sourceId,
        public int $resultVersion,
        public string $resultChecksum,
        public int $organizationId,
        public ?string $scheduleId = null,
        public ?string $state = null,
        public ?int $brokerAttempts = null,
        public ?string $nextDispatchAt = null,
    ) {}

    public static function fromRow(object $row): self
    {
        $values = get_object_vars($row);
        foreach (['outbox_id', 'source_id', 'result_version', 'result_checksum', 'organization_id'] as $required) {
            if (! array_key_exists($required, $values)) {
                throw new LogicException('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_BINDING_INVALID');
            }
        }

        return new self(
            outboxId: (string) $values['outbox_id'],
            sourceId: (string) $values['source_id'],
            resultVersion: (int) $values['result_version'],
            resultChecksum: (string) $values['result_checksum'],
            organizationId: (int) $values['organization_id'],
            scheduleId: isset($values['id']) ? (string) $values['id'] : null,
            state: isset($values['state']) ? (string) $values['state'] : null,
            brokerAttempts: isset($values['broker_attempts']) ? (int) $values['broker_attempts'] : null,
            nextDispatchAt: isset($values['next_dispatch_at']) ? (string) $values['next_dispatch_at'] : null,
        );
    }
}
