<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\AssessmentInvitation;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use Illuminate\Support\Facades\DB;

final readonly class ConsumeAssessmentInvitation
{
    public function __construct(private RlsContextRunner $runner, private ParticipantJwt $participantJwt) {}

    public function handle(string $publicId, string $token): string
    {
        $outcome = $this->runner->run(new RlsContext('service'), function () use ($publicId, $token): array {
            return DB::transaction(function () use ($publicId, $token): array {
                $invitation = AssessmentInvitation::query()
                    ->with('assessment.participant')
                    ->where('public_id', $publicId)
                    ->where('token_hash', $this->hashToken($token))
                    ->lockForUpdate()
                    ->first();

                if ($invitation === null || $invitation->status !== 'PENDING' || $invitation->active_marker !== true) {
                    throw new AssessmentInvitationRejected('Tautan psikotes sudah digunakan atau tidak lagi berlaku.', 409);
                }
                if (! $invitation->expires_at->isFuture()) {
                    $invitation->forceFill(['status' => 'EXPIRED', 'active_marker' => null])->save();

                    return ['error' => 'Tautan psikotes telah kedaluwarsa.', 'status' => 410];
                }

                $assessment = $invitation->assessment;
                if (! in_array($assessment->assessment_status, ['READY', 'IN_PROGRESS'], true)) {
                    $invitation->forceFill(['status' => 'REVOKED', 'active_marker' => null])->save();

                    return ['error' => 'Asesmen tidak lagi dapat dimulai dari tautan ini.', 'status' => 409];
                }

                $invitation->forceFill([
                    'status' => 'CONSUMED',
                    'active_marker' => null,
                    'consumed_at' => now(),
                ])->save();

                return ['token' => $this->participantJwt->issue(
                    $assessment->participant->id,
                    $assessment->participant->branch_id,
                )];
            });
        });

        if (isset($outcome['error'], $outcome['status'])) {
            throw new AssessmentInvitationRejected((string) $outcome['error'], (int) $outcome['status']);
        }

        return (string) $outcome['token'];
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
