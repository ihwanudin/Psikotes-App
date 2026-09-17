<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\AssessmentBillManualReviewError;
use DomainException;

final class AssessmentBillManualReviewException extends DomainException
{
    public function __construct(public readonly AssessmentBillManualReviewError $error)
    {
        parent::__construct($error->value);
    }
}
