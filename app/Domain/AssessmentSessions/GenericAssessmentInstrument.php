<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum GenericAssessmentInstrument: string
{
    case Ist = 'ist';
    case Papi = 'papi';
    case Rmib = 'rmib';
    case Kraepelin = 'kraepelin';

    public static function fromExternal(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new UnsupportedGenericAssessmentInstrument(
                'The instrument is not supported by the generic assessment session.',
            );
    }
}
