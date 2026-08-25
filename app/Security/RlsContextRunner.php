<?php

declare(strict_types=1);

namespace App\Security;

use App\Contracts\RunsRlsContext;
use Illuminate\Database\DatabaseManager;
use LogicException;

final class RlsContextRunner implements RunsRlsContext
{
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
            if ($connection->getDriverName() === 'pgsql') {
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

            $this->current = $context;

            try {
                return $callback();
            } finally {
                $this->current = null;
            }
        });
    }
}
