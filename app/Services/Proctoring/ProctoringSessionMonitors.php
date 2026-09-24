<?php

declare(strict_types=1);

namespace App\Services\Proctoring;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F7 (2026-09-24). Server-side cadence state for one `test_sessions` row,
 * separate from the append-only `proctor_photos`/`proctor_logs` tables so a
 * missing capture can be inferred even when the client never sends an
 * event (a dead stream that produces silence, not an error) --
 * `next_capture_due_at` is the "a photo should have arrived by now" marker
 * a future sweep can compare against `now()` to flag a gap. Must be called
 * from inside the same service-context transaction as the write it
 * accompanies (`RecordProctoringEvent`/`RecordProctoringPhoto` own that).
 */
final class ProctoringSessionMonitors
{
    /**
     * Idempotent: a second call for the same session (e.g. the first
     * event/photo of a resumed session, or a retried request) is a no-op
     * because `test_session_id` is unique.
     */
    public function ensure(int $testSessionId, string $instrument, DateTimeImmutable $startedAt): void
    {
        $minSeconds = (int) config('proctoring.capture_min_interval_seconds', 12);
        $maxSeconds = (int) config('proctoring.capture_max_interval_seconds', 20);

        DB::table('proctor_session_monitors')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'test_session_id' => $testSessionId,
            'instrument' => $instrument,
            'expected_capture_min_seconds' => $minSeconds,
            'expected_capture_max_seconds' => $maxSeconds,
            'started_at' => $this->timestamp($startedAt),
            'next_capture_due_at' => $this->timestamp($startedAt->modify("+{$maxSeconds} seconds")),
            'created_at' => $this->timestamp($startedAt),
            'updated_at' => $this->timestamp($startedAt),
        ]);
    }

    public function recordPhotoReceived(int $testSessionId, DateTimeImmutable $receivedAt): void
    {
        $monitor = DB::table('proctor_session_monitors')
            ->where('test_session_id', $testSessionId)
            ->first();
        if ($monitor === null) {
            return;
        }

        DB::table('proctor_session_monitors')
            ->where('test_session_id', $testSessionId)
            ->update([
                'last_photo_received_at' => $this->timestamp($receivedAt),
                'next_capture_due_at' => $this->timestamp(
                    $receivedAt->modify('+'.(int) $monitor->expected_capture_max_seconds.' seconds'),
                ),
                'updated_at' => $this->timestamp($receivedAt),
            ]);
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
