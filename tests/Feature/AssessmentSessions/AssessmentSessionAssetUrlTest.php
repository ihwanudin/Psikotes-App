<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\GetAssessmentSessionAssetUrl;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantJwt;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 IST reader Stage 1 (2026-09-21, Lead plan sign-off). HTTP-level
 * coverage for GET /sessions/{id}/assets/{assetId}/url, proven with a
 * synthetic `assessment_asset_references` row and a faked `ist-assets`
 * disk (Lead's plan review: "Endpoint aset di tahap 1 cukup dibuktikan
 * dengan fixture sintetis") -- FA/WU has not landed (#73), so there is no
 * real asset content to point at yet. Storage::fake() still exercises the
 * real local `serve=>true` signed-route mechanism (config/filesystems.php),
 * it just sandboxes the file root.
 */
final class AssessmentSessionAssetUrlTest extends OrganizationPaymentTestCase
{
    private const STARTED = '2026-09-21 00:00:00.000000+00:00';

    private const ENDS = '2026-09-21 01:00:00.000000+00:00';

    private const DEFAULT_NOW = '2026-09-21T00:30:00.000000+00:00';

    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
        Storage::fake('ist-assets');
        $this->bindAction(self::DEFAULT_NOW);
    }

    public function test_it_issues_a_url_capped_at_ten_minutes_when_the_session_has_more_time_left(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $assetId = $this->assetRow('ist', 'ist-assets', 'fa/legend-1-a.png');

        $response = $this->withToken($this->token($participant))
            ->getJson("/api/sessions/{$session}/assets/{$assetId}/url")
            ->assertOk();

        $this->assertSame(['url', 'expires_at'], array_keys($response->json()));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        // now=00:30, +10min=00:40, ends_at=01:00 -- the TTL ceiling wins.
        $this->assertSame('2026-09-21T00:40:00+00:00', $response->json('expires_at'));
    }

    public function test_it_caps_the_url_at_the_sessions_own_remaining_time_when_that_is_shorter(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $assetId = $this->assetRow('ist', 'ist-assets', 'fa/legend-1-a.png');

        // now=00:55, ends_at=01:00 -- five minutes left, shorter than the
        // ten-minute ceiling. expires_at must be exactly ends_at, not
        // now+10min (which would outlive the session).
        $this->bindAction('2026-09-21T00:55:00.000000+00:00');

        $response = $this->withToken($this->token($participant))
            ->getJson("/api/sessions/{$session}/assets/{$assetId}/url")
            ->assertOk();

        $this->assertSame('2026-09-21T01:00:00+00:00', $response->json('expires_at'));
    }

    public function test_it_rejects_an_asset_registered_under_a_different_instrument(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $assetId = $this->assetRow('papi', 'ist-assets', 'papi/whatever.png');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/assets/{$assetId}/url")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');
    }

    public function test_it_rejects_an_unknown_asset_id(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');

        $this->withToken($this->token($participant))
            ->getJson('/api/sessions/'.$session.'/assets/'.Str::ulid().'/url')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASSET_NOT_FOUND');
    }

    public function test_it_returns_409_session_not_started(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'created');
        $assetId = $this->assetRow('ist', 'ist-assets', 'fa/legend-1-a.png');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/assets/{$assetId}/url")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_NOT_STARTED');
    }

    /** @return iterable<string, array{string}> */
    public static function closedStatuses(): iterable
    {
        yield 'submitted' => ['submitted'];
        yield 'scored' => ['scored'];
        yield 'expired' => ['expired'];
        yield 'void' => ['void'];
    }

    #[DataProvider('closedStatuses')]
    public function test_it_returns_409_session_closed(string $status): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, $status);
        $assetId = $this->assetRow('ist', 'ist-assets', 'fa/legend-1-a.png');

        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/assets/{$assetId}/url")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SESSION_CLOSED');
    }

    public function test_it_returns_409_deadline_exceeded_one_tick_after_ends_at_without_side_effects(): void
    {
        $participant = $this->participant();
        $session = $this->sessionRow($participant, 'in_progress');
        $assetId = $this->assetRow('ist', 'ist-assets', 'fa/legend-1-a.png');

        $this->bindAction('2026-09-21T01:00:00.000001+00:00');
        $this->withToken($this->token($participant))->getJson("/api/sessions/{$session}/assets/{$assetId}/url")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DEADLINE_EXCEEDED');

        $status = DB::table('test_sessions')->where('public_id', $session)->select('status', 'expired_at')->first();
        $this->assertSame('in_progress', $status->status);
        $this->assertNull($status->expired_at);
    }

    public function test_it_returns_byte_identical_404_for_nonexistent_and_foreign_session(): void
    {
        $owner = $this->participant();
        $stranger = $this->participant();
        $session = $this->sessionRow($owner, 'in_progress');
        $assetId = $this->assetRow('ist', 'ist-assets', 'fa/legend-1-a.png');
        $token = $this->token($stranger);

        $foreign = $this->withToken($token)->getJson("/api/sessions/{$session}/assets/{$assetId}/url");
        $nonexistent = $this->withToken($token)->getJson('/api/sessions/'.Str::ulid()."/assets/{$assetId}/url");

        $this->assertSame(404, $foreign->getStatusCode());
        $this->assertSame(404, $nonexistent->getStatusCode());
        $this->assertSame($foreign->getContent(), $nonexistent->getContent());
        $foreign->assertJsonPath('error.code', 'SESSION_NOT_FOUND');
    }

    private function assetRow(string $instrument, string $disk, string $objectKey): string
    {
        $assetId = (string) Str::ulid();
        DB::table('assessment_asset_references')->insert([
            'asset_id' => $assetId,
            'instrument' => $instrument,
            'disk' => $disk,
            'object_key' => $objectKey,
            'checksum_sha256' => hash('sha256', $objectKey),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $assetId;
    }

    private function bindAction(string $iso): void
    {
        $this->app->instance(GetAssessmentSessionAssetUrl::class, new GetAssessmentSessionAssetUrl(
            $this->app->make(RlsContextRunner::class),
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        ));
    }

    private function token(int $participant): string
    {
        $branch = (int) DB::table('participants')->where('id', $participant)->value('branch_id');

        return app(ParticipantJwt::class)->issue($participant, $branch);
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
    }

    private function sessionRow(int $participant, string $status): string
    {
        $publicId = (string) Str::ulid();
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'asset-url-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 3]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        $row = [
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => $status, 'answers_revision' => 0,
            'started_at' => null, 'ends_at' => null, 'submitted_at' => null,
            'scored_at' => null, 'expired_at' => null, 'voided_at' => null, 'void_reason' => null,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ];

        $row = match ($status) {
            'created' => $row,
            'in_progress' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS],
            'submitted' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS,
                'submitted_at' => '2026-09-21 00:30:00.000000+00:00'],
            'scored' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS,
                'submitted_at' => '2026-09-21 00:30:00.000000+00:00', 'scored_at' => '2026-09-21 00:31:00.000000+00:00'],
            'expired' => [...$row, 'started_at' => self::STARTED, 'ends_at' => self::ENDS,
                'expired_at' => '2026-09-21 01:00:01.000000+00:00'],
            'void' => [...$row, 'voided_at' => '2026-09-21 00:10:00.000000+00:00', 'void_reason' => 'synthetic'],
            default => throw new InvalidArgumentException("Unknown status: {$status}"),
        };

        DB::table('test_sessions')->insert($row);

        return $publicId;
    }
}
