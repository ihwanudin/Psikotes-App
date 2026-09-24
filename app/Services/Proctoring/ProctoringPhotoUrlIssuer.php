<?php

declare(strict_types=1);

namespace App\Services\Proctoring;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Enums\AdminAbility;
use App\Models\Admin;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * F7 (2026-09-24). Same shape as `IdentityEvidenceUrlIssuer`: short-lived
 * signed URL to a private object, plus a separate service-context audit
 * log write -- with one deliberate difference. `proctor_photos`'s own RLS
 * read policy only allows `service`/`psychologist` (evidentiary content,
 * same narrower policy as `answers`/`assessment_autosave_mutations` --
 * see the migration's doc comment), so a `super_admin`/`central_admin`
 * admin's OWN rls context would be denied by RLS even though
 * `AdminAbility::ReviewProctoring` allows them at the application layer.
 * The row is therefore always loaded via `runAsService()`, same as
 * `ScoringFailuresReview` reads `assessment_scoring_attempts` -- the
 * ability check above is the entire authorization, not a supplement to
 * RLS.
 */
final readonly class ProctoringPhotoUrlIssuer
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    /** @return array{url: string, expires_at: string} */
    public function issue(Admin $admin, string $publicId): array
    {
        if (! $admin->canPerform(AdminAbility::ReviewProctoring)) {
            throw new RuntimeException('The admin is not authorized to review proctoring evidence.');
        }

        $expiresAt = CarbonImmutable::now()->addMinutes(
            (int) config('proctoring.signed_url_minutes', 15),
        );

        [$photo, $url] = $this->runner->runAsService(
            function () use ($publicId, $expiresAt): array {
                $photo = DB::table('proctor_photos')
                    ->join('test_sessions', 'test_sessions.id', '=', 'proctor_photos.test_session_id')
                    ->join('participants', 'participants.id', '=', 'test_sessions.participant_id')
                    ->where('proctor_photos.public_id', $publicId)
                    ->select(['proctor_photos.disk', 'proctor_photos.object_key', 'participants.branch_id'])
                    ->firstOrFail();

                return [
                    $photo,
                    Storage::disk((string) $photo->disk)->temporaryUrl((string) $photo->object_key, $expiresAt),
                ];
            },
        );

        $occurredAt = CarbonImmutable::now()->utc();
        $this->runner->run(new RlsContext('service'), function () use ($admin, $photo, $publicId, $expiresAt, $occurredAt): void {
            DB::table('audit_logs')->insert([
                'branch_id' => $photo->branch_id,
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'action' => 'proctor_photo.temporary_url_issued',
                'subject_type' => 'proctor_photos',
                'subject_id' => $publicId,
                'context' => json_encode(['url_expires_at' => $expiresAt->toIso8601String()], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $occurredAt),
            ]);
        });

        return [
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
