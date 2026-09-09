<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DomainException;

final class CaseAuthorizationRejected extends DomainException
{
    public function __construct()
    {
        parent::__construct('CASE_AUTHORIZATION_REJECTED');
    }
}
