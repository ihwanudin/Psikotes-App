<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Contracts\ProvidesRlsContext;
use App\Security\RlsContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ParticipantPrincipal implements Authenticatable, ProvidesRlsContext
{
    public function __construct(
        public int $participantId,
        public int $branchId,
    ) {}

    public function rlsContext(): RlsContext
    {
        return new RlsContext('participant', $this->branchId, $this->participantId);
    }

    public function getAuthIdentifierName(): string
    {
        return 'participant_id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->participantId;
    }

    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): null
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
