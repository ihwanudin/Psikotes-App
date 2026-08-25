<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Admin;
use App\Models\IdentityEvidence;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

final readonly class IdentityEvidenceUrlIssuer
{
    public function __construct(private RlsContextRunner $runner) {}

    /** @return array{url: string, expires_at: string} */
    public function issue(Admin $admin, string $publicId): array
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(
            (int) config('identity.temporary_url_minutes', 15),
        );
        [$evidence, $url] = $this->runner->run(
            $admin->rlsContext(),
            function () use ($admin, $publicId, $expiresAt): array {
                $evidence = IdentityEvidence::query()
                    ->with('participant')
                    ->where('public_id', $publicId)
                    ->firstOrFail();

                Gate::forUser($admin)->authorize('view', $evidence->participant);

                return [
                    $evidence,
                    Storage::disk($evidence->disk)->temporaryUrl(
                        $evidence->object_key,
                        $expiresAt,
                    ),
                ];
            },
        );

        $this->runner->run(new RlsContext('service'), function () use ($admin, $evidence, $expiresAt): void {
            DB::table('audit_logs')->insert([
                'branch_id' => $evidence->participant->branch_id,
                'actor_type' => 'admin',
                'actor_id' => (string) $admin->id,
                'action' => 'identity_evidence.temporary_url_issued',
                'subject_type' => IdentityEvidence::class,
                'subject_id' => $evidence->public_id,
                'context' => json_encode([
                    'evidence_type' => $evidence->type,
                    'url_expires_at' => $expiresAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'expires_at' => now()->addYears(2),
            ]);
        });

        return [
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
