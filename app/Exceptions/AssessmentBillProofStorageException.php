<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\AssessmentBillProofStorageError;
use DomainException;

final class AssessmentBillProofStorageException extends DomainException
{
    public function __construct(public readonly AssessmentBillProofStorageError $error)
    {
        parent::__construct($error->value);
    }
}
