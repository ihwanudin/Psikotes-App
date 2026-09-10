<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IdentityEvidence;
use App\Models\Participant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IdentityEvidenceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_gets_a_short_lived_url_and_the_access_is_audited(): void
    {
        Carbon::setTestNow('2024-02-29 10:15:00+07:00');
        Storage::fake('identity');
        [$branch, $participant, $evidence] = $this->evidence('A');
        $admin = $this->admin(AdminRole::Staff, $branch);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/identity-evidence/{$evidence->public_id}/temporary-url")
            ->assertOk()
            ->assertJsonStructure(['url', 'expires_at']);

        $query = parse_url((string) $response->json('url'), PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parameters);
        $this->assertArrayHasKey('expiration', $parameters);
        $this->assertSame(now()->addMinutes(15)->getTimestamp(), (int) $parameters['expiration']);
        $this->assertSame(
            now()->addMinutes(15)->toIso8601String(),
            $response->json('expires_at'),
        );
        $audit = (array) DB::table('audit_logs')->sole();
        $this->assertSame('2024-02-29 03:15:00', $audit['occurred_at']);
        $this->assertSame('2029-02-28 03:15:00', $audit['expires_at']);
        $this->assertSame([
            'evidence_type' => 'identity_document',
            'url_expires_at' => $response->json('expires_at'),
        ], json_decode((string) $audit['context'], true, 512, JSON_THROW_ON_ERROR));
        foreach ([
            $response->json('url'),
            $evidence->object_key,
            $participant->full_name,
            $participant->phone,
            $participant->birth_date?->format('Y-m-d'),
            $admin->name,
            $admin->email,
        ] as $privateValue) {
            $this->assertStringNotContainsString((string) $privateValue, (string) $audit['context']);
        }
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $branch->id,
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'action' => 'identity_evidence.temporary_url_issued',
            'subject_type' => IdentityEvidence::class,
            'subject_id' => (string) $evidence->public_id,
        ]);

        Carbon::setTestNow('2024-02-29 10:20:00+07:00');
        $this->actingAs($admin, 'admin')
            ->postJson("/admin/identity-evidence/{$evidence->public_id}/temporary-url")
            ->assertOk();

        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_unauthenticated_and_cross_branch_access_are_denied_without_audit_or_url(): void
    {
        Storage::fake('identity');
        [$branchA, , $evidence] = $this->evidence('A');
        [$branchB] = $this->evidence('B');
        $otherAdmin = $this->admin(AdminRole::Staff, $branchB);
        $url = "/admin/identity-evidence/{$evidence->public_id}/temporary-url";

        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($otherAdmin, 'admin')->postJson($url)->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNotSame($branchA->id, $branchB->id);
    }

    /** @return array{Branch, Participant, IdentityEvidence} */
    private function evidence(string $suffix): array
    {
        $branch = Branch::query()->create([
            'code' => "BR-{$suffix}",
            'name' => "Branch {$suffix}",
            'ref_code' => "REF-{$suffix}",
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'full_name' => "Participant {$suffix}",
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
        ]);
        $path = 'evidence/'.Str::random(48).'.jpg';
        Storage::disk('identity')->put($path, 'private-image', 'private');
        $evidence = IdentityEvidence::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'type' => 'identity_document',
            'disk' => 'identity',
            'object_key' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 13,
            'width' => 800,
            'height' => 600,
            'checksum_sha256' => hash('sha256', 'private-image'),
        ]);

        return [$branch, $participant, $evidence];
    }

    private function admin(AdminRole $role, ?Branch $branch): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => "Admin {$role->value}",
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
