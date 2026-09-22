<?php

declare(strict_types=1);

namespace App\Security;

use App\Contracts\RunsRlsContext;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

final class RlsContextRunner implements RunsRlsContext
{
    /** @var list<string> */
    private const array ADMIN_ROLES = ['super_admin', 'central_admin', 'branch_admin', 'staff', 'psychologist'];

    private ?RlsContext $current = null;

    public function __construct(private readonly DatabaseManager $database) {}

    public function current(): ?RlsContext
    {
        return $this->current;
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function run(RlsContext $context, callable $callback): mixed
    {
        if ($this->current !== null) {
            throw new LogicException('Nested RLS contexts are not allowed.');
        }

        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $context, $callback): mixed {
            $this->applyDatabaseContext($connection, $context);

            $this->current = $context;

            try {
                return $callback();
            } finally {
                $this->current = null;
            }
        });
    }

    public function runAsService(callable $callback): mixed
    {
        $service = new RlsContext('service');

        if ($this->current === null) {
            return $this->run($service, $callback);
        }

        if ($this->current->role === 'service') {
            return $callback();
        }

        if (! in_array($this->current->role, self::ADMIN_ROLES, true)) {
            throw new LogicException('Only administrator contexts may elevate to service.');
        }

        $connection = $this->database->connection();
        $previous = $this->current;
        $this->applyDatabaseContext($connection, $service);
        $this->current = $service;

        try {
            return $callback();
        } finally {
            try {
                $this->applyDatabaseContext($connection, $previous);
            } finally {
                $this->current = $previous;
            }
        }
    }

    private function applyDatabaseContext(Connection $connection, RlsContext $context): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->select(
            <<<'SQL'
                SELECT
                    set_config('app.role', ?, true),
                    set_config('app.branch_id', ?, true),
                    set_config('app.participant_id', ?, true)
                SQL,
            [
                $context->role,
                $context->branchId === null ? '' : (string) $context->branchId,
                $context->participantId === null ? '' : (string) $context->participantId,
            ],
        );
    }
}
