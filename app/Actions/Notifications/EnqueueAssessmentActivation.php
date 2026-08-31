<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\AssessmentParticipant;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Durable intent only. Never dispatch through the participant/order legacy consumer. */
final class EnqueueAssessmentActivation
{
    public function handle(AssessmentParticipant $attempt): void
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service' || DB::transactionLevel() < 1
            || ! $attempt->exists || ! in_array($attempt->assessment_status, ['READY', 'IN_PROGRESS'], true)) {
            throw new LogicException('Assessment activation outbox requires an activated attempt in a service transaction.');
        }

        $at = now()->toImmutable()->utc();
        DB::table('outbox_messages')->insertOrIgnore([
            'message_id' => (string) Str::ulid(),
            'deduplication_key' => hash('sha256', 'assessment.activation|'.$attempt->organization_id.'|'.$attempt->id),
            'topic' => 'assessment.activation', 'aggregate_type' => AssessmentParticipant::class,
            'aggregate_id' => (string) $attempt->id,
            'payload' => json_encode(['schema_version' => 1, 'organization_id' => $attempt->organization_id,
                'assessment_participant_id' => $attempt->id], JSON_THROW_ON_ERROR),
            'status' => 'pending', 'attempts' => 0, 'available_at' => $at,
            'expires_at' => $at->addYears(2), 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
