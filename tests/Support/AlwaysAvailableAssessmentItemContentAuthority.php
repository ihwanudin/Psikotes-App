<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\AssessmentItemContentAuthority;
use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;

/**
 * F2 item-delivery Stage 1 (2026-09-21). Shared fake for tests whose
 * subject is something other than the item-content gate itself (start
 * allocation, definition-authority behaviour, concurrency, etc.) -- lets
 * AllocateAndStartAssessmentSession's new required dependency be satisfied
 * without those tests needing to know anything about item content. Never
 * bound in the real container (see AppServiceProvider, which binds
 * RegistryAssessmentItemContentAuthority fail-closed); tests that exercise
 * the gate itself use a throwing fake or the real registry instead.
 */
final readonly class AlwaysAvailableAssessmentItemContentAuthority implements AssessmentItemContentAuthority
{
    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        ?string $currentSegmentCode = null,
    ): AssessmentItemContent {
        return new AssessmentItemContent($instrument, $definition->version, []);
    }
}
