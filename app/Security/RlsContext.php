<?php

declare(strict_types=1);

namespace App\Security;

use InvalidArgumentException;

final readonly class RlsContext
{
    /** @var list<string> */
    private const array ROLES = [
        'service',
        'super_admin',
        'central_admin',
        'branch_admin',
        'staff',
        'psychologist',
        'participant',
    ];

    public function __construct(
        public string $role,
        public ?int $branchId = null,
        public ?int $participantId = null,
    ) {
        if (! in_array($role, self::ROLES, true)) {
            throw new InvalidArgumentException('Unknown RLS role.');
        }

        if ($branchId !== null && $branchId < 1) {
            throw new InvalidArgumentException('Branch identifier must be positive.');
        }

        if ($participantId !== null && $participantId < 1) {
            throw new InvalidArgumentException('Participant identifier must be positive.');
        }

        if (in_array($role, ['branch_admin', 'staff', 'participant'], true) && $branchId === null) {
            throw new InvalidArgumentException('This RLS role requires a branch identifier.');
        }

        if ($role === 'participant' && $participantId === null) {
            throw new InvalidArgumentException('Participant RLS context requires a participant identifier.');
        }
    }
}
