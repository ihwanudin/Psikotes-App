<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

/**
 * F2 lane G7 (2026-09-20). See LoadGenericInstrumentResultSourcesForAspect —
 * this is the honest state of one configured aspect source, never collapsed
 * to a bare optional level. "Not found" and "found but ambiguous" and "result
 * exists but this exact source is missing from it" are three different,
 * distinguishable facts a caller (the Eligibility lane's G7 aggregator) needs
 * to react to differently — none of them may be silently treated as the
 * others.
 */
enum AspectSourceReadingStatus: string
{
    /** Exactly one result exists for this case+instrument, and it carries this source with a level. */
    case Found = 'found';

    /** No result row exists yet for this case+instrument at all. */
    case NotFound = 'not_found';

    /**
     * More than one result row exists for this case+instrument (e.g. a
     * retest). Picking which one is authoritative is a retest/case-authority
     * decision this reader deliberately does not make.
     */
    case Ambiguous = 'ambiguous';

    /**
     * Exactly one result exists for this case+instrument, but it does not
     * carry a source row for this exact source code. This should not happen
     * if the persistence services are correct, but is surfaced rather than
     * assumed away.
     */
    case SourceMissingFromResult = 'source_missing_from_result';
}
