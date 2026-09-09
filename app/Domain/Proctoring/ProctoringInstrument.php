<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use InvalidArgumentException;

enum ProctoringInstrument: string
{
    case Ist = 'ist';
    case Papi = 'papi';
    case Rmib = 'rmib';
    case Kraepelin = 'kraepelin';

    public static function fromAssessmentCode(string $code): self
    {
        if (trim($code) !== $code) {
            throw new InvalidArgumentException('Proctoring instrument code is invalid.');
        }

        return self::tryFrom($code)
            ?? throw new InvalidArgumentException('Proctoring instrument code is invalid.');
    }
}
