<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AcceptedConsentReader;
use App\Services\ParticipantAuth\AssessmentAccessPrerequisites;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class AcceptedConsentReaderTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->f = Fixture::create();
    }

    private function accepted(string $type = 'psychotest'): bool
    {
        return app(RlsContextRunner::class)->runAsService(fn (): bool => app(AcceptedConsentReader::class)
            ->isAccepted(Participant::findOrFail($this->f['participant']), $type));
    }

    public function test_current_accepted_evidence_at_now_is_readonly_and_does_not_change_context(): void
    {
        $before = DB::table('consent_records')->orderBy('id')->get()->toJson();
        $this->assertNull(app(RlsContextRunner::class)->current());
        app(RlsContextRunner::class)->runAsService(function (): void {
            $participant = Participant::findOrFail($this->f['participant']);
            $context = app(RlsContextRunner::class)->current();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertTrue(app(AcceptedConsentReader::class)->isAccepted($participant, 'psychotest'));
            $queries = DB::getQueryLog();
            $this->assertCount(1, $queries);
            $this->assertStringStartsWith('select exists(', $queries[0]['query']);
            $this->assertStringNotContainsString('for update', $queries[0]['query']);
            $this->assertSame($context, app(RlsContextRunner::class)->current());
        });
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($before, DB::table('consent_records')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    #[DataProvider('notAccepted')]
    public function test_changed_or_incomplete_record_is_not_accepted(array $changes): void
    {
        DB::table('consent_records')->where('participant_id', $this->f['participant'])
            ->where('consent_type', 'psychotest')->update($changes);
        $this->assertFalse($this->accepted());
    }

    public static function notAccepted(): iterable
    {
        yield 'declined' => [['status' => 'declined']];
        yield 'withdrawn status' => [['status' => 'withdrawn']];
        yield 'withdrawn timestamp' => [['withdrawn_at' => '2020-01-01']];
        yield 'future withdrawal is still nonnull' => [['withdrawn_at' => '2099-01-01']];
        yield 'missing consent timestamp' => [['consented_at' => null]];
        yield 'future consent timestamp' => [['consented_at' => '2099-01-01']];
        yield 'old version' => [['document_version' => 'superseded']];
        yield 'wrong hash' => [['document_hash' => str_repeat('f', 64)]];
    }

    public function test_document_changes_are_read_fresh_including_text_with_the_same_version(): void
    {
        $reader = app(AcceptedConsentReader::class);
        app(RlsContextRunner::class)->runAsService(function () use ($reader): void {
            $participant = Participant::findOrFail($this->f['participant']);
            $document = config('consent.documents.psychotest');
            $this->assertTrue($reader->isAccepted($participant, 'psychotest'));
            config()->set('consent.documents.psychotest.text', $document['text'].' Revised synthetic text.');
            $this->assertFalse($reader->isAccepted($participant, 'psychotest'));
            config()->set('consent.documents.psychotest', $document);
            config()->set('consent.documents.psychotest.version', 'next-version');
            $this->assertFalse($reader->isAccepted($participant, 'psychotest'));
            config()->set('consent.documents.psychotest', $document);
            config()->set('consent.documents.psychotest.title', 'New presentation title');
            $this->assertTrue($reader->isAccepted($participant, 'psychotest'));
        });
    }

    public function test_missing_record_cannot_use_the_other_consent_type(): void
    {
        DB::table('consent_records')->where('participant_id', $this->f['participant'])
            ->where('consent_type', 'psychotest')->delete();
        $this->assertFalse($this->accepted());
        $this->assertTrue($this->accepted('dass'));
    }

    public function test_another_participants_accepted_records_never_satisfy_this_participant(): void
    {
        Fixture::create(identity: ['organization' => $this->f['organization']]);
        Fixture::create();
        DB::table('consent_records')->where('participant_id', $this->f['participant'])->delete();
        $this->assertSame(4, DB::table('consent_records')->count());
        $this->assertFalse($this->accepted());
        $this->assertFalse($this->accepted('dass'));
    }

    public function test_dass_decline_does_not_block_main_prerequisites(): void
    {
        DB::table('consent_records')->where('participant_id', $this->f['participant'])
            ->where('consent_type', 'dass')->update(['status' => 'declined']);
        $this->assertTrue($this->accepted());
        $this->assertFalse($this->accepted('dass'));
        app(RlsContextRunner::class)->runAsService(function (): void {
            $participant = Participant::findOrFail($this->f['participant']);
            app(AssessmentAccessPrerequisites::class)->assertSatisfied($participant, 'ist');
            $this->expectException(EntitlementLocked::class);
            app(AssessmentAccessPrerequisites::class)->assertSatisfied($participant, 'dass21');
        });
    }

    #[DataProvider('invalidDocuments')]
    public function test_existing_config_exception_is_preserved(mixed $document): void
    {
        config()->set('consent.documents.psychotest', $document);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or invalid consent document [psychotest].');
        $this->accepted();
    }

    public static function invalidDocuments(): iterable
    {
        yield 'absent' => [null];
        yield 'not array' => ['bad-config'];
        yield 'missing text' => [['version' => 'v1', 'title' => 'Synthetic']];
        yield 'wrong version type' => [['version' => 1, 'title' => 'Synthetic', 'text' => 'Synthetic']];
    }

    public function test_empty_string_document_semantics_are_not_hardened_by_this_extraction(): void
    {
        config()->set('consent.documents.psychotest', ['version' => '', 'title' => '', 'text' => '']);
        $document = ConsentDocument::for('psychotest');
        DB::table('consent_records')->where('participant_id', $this->f['participant'])->where('consent_type', 'psychotest')
            ->update(['document_version' => $document->version, 'document_hash' => $document->hash]);
        $this->assertTrue($this->accepted()); // Evidence predicate only; not legal approval of configured text.
    }

    public function test_bad_config_is_not_resolved_before_existing_profile_checks(): void
    {
        config()->set('consent.documents.psychotest', null);
        $participant = Participant::findOrFail($this->f['participant']);
        $participant->full_name = null;
        $this->expectException(EntitlementLocked::class);
        app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentAccessPrerequisites::class)
            ->assertSatisfied($participant, 'ist'));
    }

    public function test_bad_config_in_prerequisites_still_propagates_without_translation(): void
    {
        config()->set('consent.documents.psychotest', null);
        $participant = Participant::findOrFail($this->f['participant']);
        $this->expectException(InvalidArgumentException::class);
        app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentAccessPrerequisites::class)
            ->assertSatisfied($participant, 'ist'));
    }
}
