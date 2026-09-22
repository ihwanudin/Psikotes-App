<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\AssessmentSessions\GetAssessmentSessionItems;
use App\Contracts\AssessmentItemContentAuthority;
use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Http\Controllers\GetAssessmentSessionItemsController;
use App\Security\RlsContextRunner;
use App\Services\AssessmentSessions\RegistryAssessmentItemContentAuthority;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * F2 item-delivery (2026-09-21). PostgreSQL HTTP-level evidence for
 * GET /sessions/{id}/items, same convention as
 * AssessmentSessionAnswersReadbackControllerTest.php (controller invoked
 * directly with a manually-built Request carrying participant_principal,
 * against the real psikotes_runtime non-owner/NOBYPASSRLS role).
 *
 * No torn-read/concurrency test here -- unlike answers, item content isn't
 * read from a table another action writes to concurrently; the only
 * PostgreSQL-specific question is whether RLS changes the session lookup or
 * the fail-closed rejection, which the tests below cover directly. The
 * fail-closed-under-real-registry test mirrors the mandatory PostgreSQL
 * proof Lead required for the /start item-content gate in PR #61 -- the
 * same production binding (RegistryAssessmentItemContentAuthority([])) must
 * reject a read the same way it rejects a start.
 */
final class AssessmentSessionItemsReadbackControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_happy_path_through_the_real_controller_under_postgres(): void
    {
        $identity = DB::selectOne(
            'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
        );
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        $fixture = $this->fixture();

        $response = (new GetAssessmentSessionItemsController)(
            $this->principalRequest($fixture['participant'], $fixture['branch']),
            $fixture['public_id'],
            $this->readAction($this->fakeReader(), '2026-09-08T03:30:00+00:00'),
        );

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame($fixture['public_id'], $body['session_id']);
        $this->assertSame('ist', $body['instrument']);
        $this->assertSame('synthetic-content-v1', $body['version']);
        $this->assertSame([
            ['code' => 'SYN', 'items' => [['item_no' => 1, 'text' => 'first']]],
        ], $body['subtests']);
    }

    public function test_foreign_session_is_404_through_the_real_controller_under_postgres_rls(): void
    {
        $owner = $this->fixture();
        $stranger = $this->graph();

        $response = (new GetAssessmentSessionItemsController)(
            $this->principalRequest($stranger['participant'], $stranger['branch']),
            $owner['public_id'],
            $this->readAction($this->fakeReader(), '2026-09-08T03:30:00+00:00'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('SESSION_NOT_FOUND', $response->getData(true)['error']['code']);
    }

    public function test_the_real_fail_closed_registry_rejects_a_read_with_503_under_postgres(): void
    {
        $fixture = $this->fixture();

        $response = (new GetAssessmentSessionItemsController)(
            $this->principalRequest($fixture['participant'], $fixture['branch']),
            $fixture['public_id'],
            $this->readAction(new RegistryAssessmentItemContentAuthority([]), '2026-09-08T03:30:00+00:00'),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('ASSESSMENT_ITEM_CONTENT_UNAVAILABLE', $response->getData(true)['error']['code']);
    }

    private function fakeReader(): AssessmentItemContentAuthority
    {
        return new class implements AssessmentItemContentAuthority
        {
            public function contentFor(
                GenericAssessmentInstrument $instrument,
                SessionDefinition $definition,
                int $participantId,
                ?string $lockedVariant = null,
            ): AssessmentItemContent {
                return new AssessmentItemContent($instrument, 'synthetic-content-v1', [
                    ['code' => 'SYN', 'items' => [['item_no' => 1, 'text' => 'first']]],
                ]);
            }
        };
    }

    private function readAction(AssessmentItemContentAuthority $authority, string $iso): GetAssessmentSessionItems
    {
        return new GetAssessmentSessionItems(
            app(RlsContextRunner::class),
            $authority,
            fn (): DateTimeImmutable => new DateTimeImmutable($iso),
        );
    }

    private function principalRequest(int $participant, int $branch): Request
    {
        $request = Request::create('/api/sessions/x/items', 'GET');
        $request->attributes->set('participant_principal', new ParticipantPrincipal($participant, $branch));

        return $request;
    }

    /** @return array{branch:int,participant:int} */
    private function graph(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => 'Items Readback Synthetic',
                'organization_code' => $key, 'display_name' => 'Items Readback Synthetic',
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'full_name' => 'Items Readback Synthetic',
                'phone' => '620000000000',
            ]);

            return compact('branch', 'participant');
        });
    }

    /** @return array{branch:int,participant:int,session:int,public_id:string} */
    private function fixture(): array
    {
        $graph = $this->graph();

        return app(RlsContextRunner::class)->runAsService(function () use ($graph): array {
            $definitionSource = [
                'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
                'provenance' => 'items-readback-pg-test-only', 'total_duration_seconds' => 3600,
                'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 1]],
                'randomization' => 'fixed', 'seed' => null, 'generator' => null,
            ];
            $definition = SessionDefinition::fromArray([
                ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
            ]);
            $publicId = (string) Str::ulid();
            $session = DB::table('test_sessions')->insertGetId([
                'public_id' => $publicId, 'participant_id' => $graph['participant'],
                'test_type' => 'ist', 'attempt_no' => 1,
                'authorization_id' => (string) Str::ulid(),
                'allocation_intent_id' => (string) Str::ulid(),
                'duration_seconds' => 3600, 'status' => 'in_progress', 'answers_revision' => 0,
                'started_at' => '2026-09-08 03:00:00.000000+00',
                'ends_at' => '2026-09-08 04:00:00.000000+00',
                'session_definition_version' => $definition->version,
                'session_definition_provenance' => $definition->provenance,
                'session_definition_checksum' => $definition->checksum,
                'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
            ]);

            return [...$graph, 'session' => $session, 'public_id' => $publicId];
        });
    }
}
