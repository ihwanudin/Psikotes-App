<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Data\Integrations\IssuedAssessmentInvitation;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentInvitation;
use App\Models\AssessmentParticipant;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class IssueAssessmentInvitation
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(Admin $admin, AssessmentParticipant $assessment): IssuedAssessmentInvitation
    {
        if (! $admin->canPerform(AdminAbility::EditParticipants)
            || ($admin->role !== AdminRole::SuperAdmin && $admin->branch_id !== $assessment->organization_id)) {
            throw new AuthorizationException('Not authorized to issue this assessment invitation.');
        }

        $token = Str::random(64);
        $auditAt = now()->utc()->toImmutable();
        $expiresAt = $auditAt->addHours(max(1, min(720, (int) config('assessment_integration.invitation_ttl_hours', 168))));

        $publicId = $this->runner->runAsService(function () use ($admin, $assessment, $token, $auditAt, $expiresAt): string {
            return DB::transaction(function () use ($admin, $assessment, $token, $auditAt, $expiresAt): string {
                $locked = AssessmentParticipant::query()->lockForUpdate()->findOrFail($assessment->id);
                if (! in_array($locked->assessment_status, ['READY', 'IN_PROGRESS'], true)) {
                    throw new LogicException('Invitations are only available for active assessments.');
                }

                $latestIssue = (int) AssessmentInvitation::query()
                    ->where('assessment_participant_id', $locked->id)
                    ->max('issue_number');
                AssessmentInvitation::query()
                    ->where('assessment_participant_id', $locked->id)
                    ->where('active_marker', true)
                    ->update(['active_marker' => null, 'status' => 'REVOKED', 'updated_at' => now()]);

                $invitation = AssessmentInvitation::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'assessment_participant_id' => $locked->id,
                    'issued_by_admin_id' => $admin->id,
                    'token_hash' => $this->hashToken($token),
                    'active_marker' => true,
                    'status' => 'PENDING',
                    'issue_number' => $latestIssue + 1,
                    'expires_at' => $expiresAt,
                ]);

                DB::table('audit_logs')->insert([
                    'branch_id' => $locked->organization_id,
                    'actor_type' => 'admin',
                    'actor_id' => (string) $admin->id,
                    'action' => $latestIssue === 0 ? 'assessment_invitation.issued' : 'assessment_invitation.reissued',
                    'subject_type' => AssessmentParticipant::class,
                    'subject_id' => (string) $locked->id,
                    'context' => json_encode([
                        'invitation_id' => $invitation->public_id,
                        'issue_number' => $invitation->issue_number,
                        'expires_at' => $expiresAt->toISOString(),
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $auditAt,
                    'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $auditAt),
                ]);

                return $invitation->public_id;
            });
        });

        return new IssuedAssessmentInvitation(
            route('assessment.invitations.show', ['publicId' => $publicId]).'#token='.rawurlencode($token),
            $token,
            $expiresAt,
        );
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
