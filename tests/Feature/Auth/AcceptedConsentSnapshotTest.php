<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AcceptedConsentReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class AcceptedConsentSnapshotTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00 UTC'));
        $this->asOf = CarbonImmutable::instance(now());
        $this->fixture = Fixture::create();
    }

    private function accepted(ConsentDocument $document, ?CarbonImmutable $asOf = null): bool
    {
        return app(RlsContextRunner::class)->runAsService(fn (): bool => app(AcceptedConsentReader::class)
            ->isAcceptedForDocumentAt(Participant::findOrFail($this->fixture['participant']), $document, $asOf ?? $this->asOf));
    }

    private function change(array $changes): void
    {
        DB::table('consent_records')->where('participant_id', $this->fixture['participant'])
            ->where('consent_type', 'psychotest')->update($changes);
    }

    public function test_captured_document_and_time_survive_later_config_and_clock_changes_without_mutation(): void
    {
        $document = ConsentDocument::for('psychotest');
        $before = DB::table('consent_records')->orderBy('id')->get()->toJson();
        config()->set('consent.documents.psychotest', null);
        $this->travelTo($this->asOf->subDay());
        app(RlsContextRunner::class)->runAsService(function () use ($document): void {
            $participant = Participant::findOrFail($this->fixture['participant']);
            $context = app(RlsContextRunner::class)->current();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $this->assertTrue(app(AcceptedConsentReader::class)->isAcceptedForDocumentAt($participant, $document, $this->asOf));
                $queries = DB::getQueryLog();
                $this->assertCount(1, $queries);
                $this->assertStringStartsWith('select exists(', $queries[0]['query']);
                $this->assertStringNotContainsString('for update', $queries[0]['query']);
            } finally {
                DB::disableQueryLog();
            }
            $this->assertSame($context, app(RlsContextRunner::class)->current());
        });
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertNull(config('consent.documents.psychotest'));
        $this->assertTrue(now()->equalTo($this->asOf->subDay()));
        $this->assertSame($before, DB::table('consent_records')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_advancing_the_clock_does_not_admit_consent_after_captured_time(): void
    {
        $document = ConsentDocument::for('psychotest');
        $this->change(['consented_at' => $this->asOf->addSecond()]);
        $this->travelTo($this->asOf->addDay());
        $this->assertFalse($this->accepted($document));
        $this->assertTrue($this->accepted($document, $this->asOf->addSecond()));
        $this->assertTrue(app(RlsContextRunner::class)->runAsService(fn (): bool => app(AcceptedConsentReader::class)
            ->isAccepted(Participant::findOrFail($this->fixture['participant']), 'psychotest')));
    }

    public function test_legacy_loads_fresh_document_while_explicit_method_keeps_captured_hash(): void
    {
        $document = ConsentDocument::for('psychotest');
        config()->set('consent.documents.psychotest.text', $document->text.' Revised synthetic text.');
        $this->assertTrue($this->accepted($document));
        $this->assertFalse($this->accepted(ConsentDocument::for('psychotest')));
        $this->assertFalse(app(RlsContextRunner::class)->runAsService(fn (): bool => app(AcceptedConsentReader::class)
            ->isAccepted(Participant::findOrFail($this->fixture['participant']), 'psychotest')));
    }

    #[DataProvider('recordStates')]
    public function test_explicit_evaluation_preserves_canonical_record_predicate(array $changes, bool $expected): void
    {
        $document = ConsentDocument::for('psychotest');
        $this->change($changes);
        $this->assertSame($expected, $this->accepted($document));
    }

    public static function recordStates(): iterable
    {
        yield 'exact boundary' => [['consented_at' => '2026-09-04 10:00:00'], true];
        yield 'before boundary' => [['consented_at' => '2026-09-04 09:59:59'], true];
        yield 'future' => [['consented_at' => '2026-09-04 10:00:01'], false];
        yield 'missing timestamp' => [['consented_at' => null], false];
        yield 'old version' => [['document_version' => 'old'], false];
        yield 'old hash' => [['document_hash' => str_repeat('f', 64)], false];
        yield 'declined' => [['status' => 'declined'], false];
        yield 'withdrawn status' => [['status' => 'withdrawn'], false];
        yield 'withdrawn timestamp' => [['withdrawn_at' => '2026-09-04 09:59:59'], false];
        // asOf bounds consent acceptance time, not a historical reconstruction of withdrawal.
        yield 'future withdrawal remains nonnull' => [['withdrawn_at' => '2026-09-05 10:00:00'], false];
    }

    public function test_document_supplies_its_own_consent_type_and_never_uses_other_participant(): void
    {
        Fixture::create();
        DB::table('consent_records')->where('participant_id', $this->fixture['participant'])
            ->where('consent_type', 'psychotest')->delete();
        $this->assertFalse($this->accepted(ConsentDocument::for('psychotest')));
        $this->assertTrue($this->accepted(ConsentDocument::for('dass')));
        DB::table('consent_records')->where('participant_id', $this->fixture['participant'])->delete();
        $this->assertFalse($this->accepted(ConsentDocument::for('dass')));
    }

    public function test_blank_string_document_remains_compatible_for_both_methods(): void
    {
        config()->set('consent.documents.psychotest', ['version' => '', 'title' => '', 'text' => '']);
        $document = ConsentDocument::for('psychotest');
        $this->change(['document_version' => '', 'document_hash' => $document->hash]);
        $this->assertTrue($this->accepted($document));
        $this->assertTrue(app(RlsContextRunner::class)->runAsService(fn (): bool => app(AcceptedConsentReader::class)
            ->isAccepted(Participant::findOrFail($this->fixture['participant']), 'psychotest')));
    }

    public function test_legacy_config_exception_still_propagates_with_an_existing_captured_document(): void
    {
        $document = ConsentDocument::for('psychotest');
        config()->set('consent.documents.psychotest', null);
        $this->assertTrue($this->accepted($document));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or invalid consent document [psychotest].');
        app(RlsContextRunner::class)->runAsService(fn (): bool => app(AcceptedConsentReader::class)
            ->isAccepted(Participant::findOrFail($this->fixture['participant']), 'psychotest'));
    }
}
