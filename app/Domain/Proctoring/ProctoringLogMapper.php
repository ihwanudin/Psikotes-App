<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use stdClass;

/**
 * F7 (2026-09-24). `ProctoringValidityPolicy::decide()` takes
 * `ProctoringEvent`/`ProctoringAdjudicatedFinding` value objects, not raw
 * rows -- this is the one place that reconstructs them from
 * `proctor_logs`/`proctor_adjudications` rows, so any future caller (report
 * generation, an admin review page) does the mapping once, the same way,
 * rather than re-deriving it inline.
 */
final class ProctoringLogMapper
{
    public function eventFromRow(stdClass $row): ProctoringEvent
    {
        return new ProctoringEvent(
            evidenceId: (string) $row->evidence_id,
            instrument: ProctoringInstrument::from((string) $row->instrument),
            kind: ProctoringEventKind::from((string) $row->event_kind),
            source: ProctoringEvidenceSource::from((string) $row->evidence_source),
        );
    }

    public function adjudicationFromRow(stdClass $row): ProctoringAdjudicatedFinding
    {
        return new ProctoringAdjudicatedFinding(
            findingId: (string) $row->public_id,
            sourceEvidenceId: (string) $row->source_evidence_id,
            kind: ProctoringAdjudicatedFindingKind::from((string) $row->finding_kind),
            adjudicatorId: (string) $row->adjudicator_admin_id,
            adjudicationToken: (string) $row->adjudication_token,
        );
    }
}
