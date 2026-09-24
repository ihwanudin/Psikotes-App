<?php

declare(strict_types=1);

namespace Tests\Unit\Proctoring;

use App\Domain\Proctoring\ProctoringAdjudicatedFinding;
use App\Domain\Proctoring\ProctoringEvent;
use App\Domain\Proctoring\ProctoringLogMapper;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * F7 (2026-09-24). Proves the row -> value-object mapping a future
 * `ProctoringValidityPolicy::decide()` caller relies on round-trips
 * exactly -- same fields, same validation (an invalid stored combination
 * would throw here too, not silently pass through).
 */
final class ProctoringLogMapperTest extends TestCase
{
    public function test_a_log_row_maps_to_an_equivalent_proctoring_event(): void
    {
        $row = new stdClass;
        $row->evidence_id = 'evidence-123';
        $row->instrument = 'ist';
        $row->event_kind = 'CAMERA_INTERRUPTED';
        $row->evidence_source = 'CLIENT_OBSERVATION';

        $event = (new ProctoringLogMapper)->eventFromRow($row);

        $this->assertInstanceOf(ProctoringEvent::class, $event);
        $this->assertSame('evidence-123', $event->evidenceId);
        $this->assertSame('ist', $event->instrument->value);
        $this->assertSame('CAMERA_INTERRUPTED', $event->kind->value);
        $this->assertSame('CLIENT_OBSERVATION', $event->source->value);
    }

    public function test_an_adjudication_row_maps_to_an_equivalent_finding(): void
    {
        $row = new stdClass;
        $row->public_id = 'finding-1';
        $row->source_evidence_id = 'evidence-123';
        $row->finding_kind = 'SIGNAL_DISMISSED';
        $row->adjudicator_admin_id = '42';
        $row->adjudication_token = 'a-real-token';

        $finding = (new ProctoringLogMapper)->adjudicationFromRow($row);

        $this->assertInstanceOf(ProctoringAdjudicatedFinding::class, $finding);
        $this->assertSame('finding-1', $finding->findingId);
        $this->assertSame('evidence-123', $finding->sourceEvidenceId);
        $this->assertSame('42', $finding->adjudicatorId);
        $this->assertFalse($finding->confirmsV3());
    }
}
