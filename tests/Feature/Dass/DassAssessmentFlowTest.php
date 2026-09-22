<?php

declare(strict_types=1);

namespace Tests\Feature\Dass;

use App\Actions\Dass\AllocateAndStartDassAssessment;
use App\Actions\Dass\GetDassAssessment;
use App\Actions\Dass\GetDassResultSummary;
use App\Actions\Dass\SubmitDassAssessment;
use App\Domain\Dass\Dass21ConfigUnavailable;
use App\Domain\Dass\DassAssessmentStatus;
use App\Domain\Dass\InvalidDassAssessmentState;
use App\Domain\Report\DassScreeningSummary;
use App\Services\Dass\DassTableNames;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

/**
 * Exercises the DASS-21 session-taking actions this task added:
 * AllocateAndStartDassAssessment, GetDassAssessment, SubmitDassAssessment,
 * GetDassResultSummary. Real Dass21Scorer/Dass21ScreeningPolicy against
 * the REAL dass21.json content, plus one field (minimum_completion_seconds)
 * this test's own instrument_versions row adds on top -- verified missing
 * from the real seeded file (see Dass21ConfigReader's doc); the "real data
 * without it fails closed" behaviour is proven separately below.
 */
final class DassAssessmentFlowTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = Fixture::create('dass21');
        DB::table('entitlements')->insert([
            'participant_id' => $this->fixture['participant'],
            'test_type' => 'dass21',
            'status' => 'ready',
            'ready_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedDass21Config();
    }

    private function seedDass21Config(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/dass21.json');
        if ($contents === false) {
            throw new RuntimeException('Unable to read the DASS-21 seed file.');
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        // Test-only addition -- the real seeded file has no
        // minimum_completion_seconds yet; see Dass21ConfigReader's doc.
        $decoded['minimum_completion_seconds'] = 5;
        $payload = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('instrument_versions')->insert([
            'code' => 'dass21',
            'version' => 'test-1',
            'source_file' => 'dass21.json',
            'checksum' => hash('sha256', $payload),
            'payload' => $payload,
            'source_text' => $payload,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function principal(): ParticipantPrincipal
    {
        return new ParticipantPrincipal($this->fixture['participant'], $this->fixture['organization']);
    }

    /** Same values Dass21ScorerTest already proves score to Normal (level 1). */
    private function normalResponses(): array
    {
        $config = json_decode((string) DB::table('instrument_versions')->where('code', 'dass21')->value('source_text'), true, flags: JSON_THROW_ON_ERROR);
        $responses = [];
        foreach ($config['items'] as $item) {
            $responses[] = ['item' => $item['item'], 'score' => 0];
        }

        return $responses;
    }

    public function test_start_allocates_once_then_replays(): void
    {
        $first = app(AllocateAndStartDassAssessment::class)->execute($this->principal());
        $this->assertSame(DassAssessmentStatus::InProgress, $first->status);
        $this->assertNotNull($first->startedAt);
        $this->assertNull($first->completedAt);
        $this->assertDatabaseCount(DassTableNames::assessments(), 1);

        $second = app(AllocateAndStartDassAssessment::class)->execute($this->principal());
        $this->assertSame($first->publicId, $second->publicId);
        $this->assertSame($first->startedAt, $second->startedAt);
        $this->assertDatabaseCount(DassTableNames::assessments(), 1);
    }

    public function test_start_rejects_a_participant_without_the_dass21_entitlement(): void
    {
        DB::table('entitlements')->where('participant_id', $this->fixture['participant'])->where('test_type', 'dass21')->delete();
        $this->expectException(EntitlementLocked::class);
        app(AllocateAndStartDassAssessment::class)->execute($this->principal());
    }

    public function test_submit_scores_persists_and_completes_then_replays_idempotently(): void
    {
        $started = app(AllocateAndStartDassAssessment::class)->execute($this->principal());

        $completed = app(SubmitDassAssessment::class)->execute($this->fixture['participant'], $started->publicId, $this->normalResponses());

        $this->assertSame(DassAssessmentStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completedAt);
        $this->assertDatabaseCount(DassTableNames::responses(), 21);
        $this->assertDatabaseCount(DassTableNames::results(), 1);
        $result = DB::table(DassTableNames::results())->sole();
        $this->assertSame('Normal', $result->overall_category);
        $this->assertSame('Normal', $result->depression_category);
        $this->assertSame(0, (int) $result->depression_raw);
        $this->assertSame('none', $result->follow_up);
        $this->assertDatabaseHas(DassTableNames::assessments(), ['public_id' => $started->publicId, 'status' => 'completed']);

        // Idempotent replay: submitting again must not duplicate rows or re-score.
        $replayed = app(SubmitDassAssessment::class)->execute($this->fixture['participant'], $started->publicId, $this->normalResponses());
        $this->assertSame($completed->completedAt, $replayed->completedAt);
        $this->assertDatabaseCount(DassTableNames::responses(), 21);
        $this->assertDatabaseCount(DassTableNames::results(), 1);
    }

    public function test_submit_rejects_another_participants_assessment(): void
    {
        $started = app(AllocateAndStartDassAssessment::class)->execute($this->principal());
        $stranger = Fixture::create('dass21');

        $this->expectException(InvalidDassAssessmentState::class);
        app(SubmitDassAssessment::class)->execute($stranger['participant'], $started->publicId, $this->normalResponses());
    }

    public function test_submit_fails_closed_when_minimum_completion_seconds_is_missing_from_real_seeded_data(): void
    {
        // Overwrite the test-only config with the REAL seeded file content
        // (no minimum_completion_seconds) to prove Dass21ConfigReader's
        // fail-closed behaviour against actual production data, not just a
        // synthetic gap.
        $real = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/dass21.json');
        DB::table('instrument_versions')->where('code', 'dass21')->update([
            'checksum' => hash('sha256', $real),
            'source_text' => $real,
        ]);
        $started = app(AllocateAndStartDassAssessment::class)->execute($this->principal());

        $this->expectException(Dass21ConfigUnavailable::class);
        app(SubmitDassAssessment::class)->execute($this->fixture['participant'], $started->publicId, $this->normalResponses());
    }

    public function test_get_assessment_returns_status_only_and_respects_ownership(): void
    {
        $started = app(AllocateAndStartDassAssessment::class)->execute($this->principal());

        $own = app(GetDassAssessment::class)->execute($this->fixture['participant'], $started->publicId);
        $this->assertNotNull($own);
        $this->assertSame(DassAssessmentStatus::InProgress, $own->status);

        $stranger = Fixture::create('dass21');
        $strangersView = app(GetDassAssessment::class)->execute($stranger['participant'], $started->publicId);
        $this->assertNull($strangersView);

        $missing = app(GetDassAssessment::class)->execute($this->fixture['participant'], (string) Str::ulid());
        $this->assertNull($missing);
    }

    public function test_result_summary_is_null_until_completed_then_carries_only_general_category_material(): void
    {
        $started = app(AllocateAndStartDassAssessment::class)->execute($this->principal());

        $beforeSubmit = app(GetDassResultSummary::class)->execute($this->fixture['participant'], $started->publicId);
        $this->assertNull($beforeSubmit);

        app(SubmitDassAssessment::class)->execute($this->fixture['participant'], $started->publicId, $this->normalResponses());

        $summary = app(GetDassResultSummary::class)->execute($this->fixture['participant'], $started->publicId);
        $this->assertInstanceOf(DassScreeningSummary::class, $summary);
        $this->assertSame('Normal', $summary->generalCategory);
        $this->assertNotSame('', trim($summary->narrative));
        $this->assertNull($summary->followUp);

        $array = $summary->toArray();
        $this->assertSame(['general_category', 'narrative', 'follow_up'], array_keys($array));
    }
}
