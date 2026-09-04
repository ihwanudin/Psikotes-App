<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Participant;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentAccessPrerequisites;
use App\Services\ParticipantAuth\AssessmentPrerequisiteFrame;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class AssessmentPrerequisiteFrameTest extends OrganizationPaymentTestCase
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

    private function frame(string $zone = 'UTC', bool $dass = true): AssessmentPrerequisiteFrame
    {
        return new AssessmentPrerequisiteFrame($this->asOf, $zone, ConsentDocument::for('psychotest'), $dass ? ConsentDocument::for('dass') : null);
    }

    private function satisfied(?AssessmentPrerequisiteFrame $frame, string $type = 'ist'): bool
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($frame, $type): bool {
            $participant = Participant::findOrFail($this->fixture['participant']);
            $reader = app(AssessmentAccessPrerequisites::class);
            try {
                if ($frame === null) {
                    $reader->assertSatisfied($participant, $type);
                } else {
                    $reader->assertSatisfiedAt($participant, $type, $frame);
                }

                return true;
            } catch (EntitlementLocked) {
                return false;
            }
        });
    }

    public function test_captured_documents_and_instant_ignore_later_config_and_clock_without_writes(): void
    {
        $frame = $this->frame();
        $before = $this->rows();
        config()->set('consent.documents', null);
        $this->travelTo($this->asOf->subDay());
        $this->assertTrue($this->satisfied($frame, 'dass21'));
        $this->assertNull(config('consent.documents'));
        $this->assertTrue(now()->equalTo($this->asOf->subDay()));
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($before, $this->rows());
    }

    #[DataProvider('calendarCases')]
    public function test_birth_date_uses_captured_server_calendar_not_utc_midnight(string $instant, string $zone, string $birth, string $day, bool $expected): void
    {
        $this->asOf = CarbonImmutable::parse($instant);
        // Fixture evidence must precede this instant, independently of the birth-date check.
        foreach (['identity_verifications' => 'checked_at', 'identity_evidence' => 'updated_at', 'consent_records' => 'consented_at'] as $table => $column) {
            DB::table($table)->update([$column => $this->asOf->subDay()]);
        }
        DB::table('participants')->where('id', $this->fixture['participant'])->update(['birth_date' => $birth]);
        $frame = $this->frame($zone);
        config()->set('app.timezone', 'Asia/Tokyo');
        $this->travelTo($this->asOf->addDays(2));
        $this->assertSame($day, $frame->serverDate);
        $this->assertSame($expected, $this->satisfied($frame));
    }

    public static function calendarCases(): iterable
    {
        yield 'west today denied' => ['2026-09-04 00:30:00 UTC', 'America/Los_Angeles', '2026-09-03', '2026-09-03', false];
        yield 'west yesterday allowed' => ['2026-09-04 00:30:00 UTC', 'America/Los_Angeles', '2026-09-02', '2026-09-03', true];
        yield 'east yesterday allowed' => ['2026-09-04 23:30:00 UTC', 'Asia/Tokyo', '2026-09-04', '2026-09-05', true];
        yield 'east today denied' => ['2026-09-04 23:30:00 UTC', 'Asia/Tokyo', '2026-09-05', '2026-09-05', false];
    }

    #[DataProvider('identityCases')]
    public function test_identity_and_evidence_use_captured_boundary(array $changes, bool $expected): void
    {
        $frame = $this->frame();
        $reviewer = Admin::create(['name' => 'Synthetic', 'email' => 'frame@example.test', 'password' => 'synthetic', 'role' => AdminRole::SuperAdmin]);
        DB::table('identity_verifications')->update([
            'outcome' => 'mismatch', 'manual_status' => 'accepted', 'reviewed_by_admin_id' => $reviewer->id,
            'reviewed_at' => $this->asOf, ...$changes,
        ]);
        $this->travelTo($this->asOf->addDay());
        $this->assertSame($expected, $this->satisfied($frame));
    }

    public static function identityCases(): iterable
    {
        yield 'exact manual acceptance' => [[], true];
        yield 'same instant offset' => [['reviewed_at' => '2026-09-04 17:00:00+07:00'], true];
        yield 'future checked' => [['checked_at' => '2026-09-04 10:00:01'], false];
        yield 'future review' => [['reviewed_at' => '2026-09-04 10:00:01'], false];
        yield 'review before check' => [['reviewed_at' => '2026-09-04 09:59:59'], false];
        yield 'reviewer missing' => [['reviewed_by_admin_id' => null], false];
        yield 'review time missing' => [['reviewed_at' => null], false];
        yield 'automatic match unchanged' => [['manual_status' => 'pending', 'outcome' => 'match', 'reviewed_at' => null], true];
        yield 'rejected match' => [['manual_status' => 'rejected', 'outcome' => 'match'], false];
    }

    public function test_future_consent_and_replaced_or_missing_evidence_remain_locked(): void
    {
        $frame = $this->frame();
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['consented_at' => $this->asOf->addSecond()]);
        $this->travelTo($this->asOf->addDay());
        $this->assertFalse($this->satisfied($frame));
        DB::table('consent_records')->update(['consented_at' => $this->asOf]);
        DB::table('identity_evidence')->update(['updated_at' => $this->asOf->addSecond()]);
        $this->assertFalse($this->satisfied($frame));
        DB::table('identity_evidence')->update(['updated_at' => $this->asOf]);
        $this->assertTrue($this->satisfied($frame));
        DB::table('identity_evidence')->where('type', 'initial_selfie')->delete();
        $this->assertFalse($this->satisfied($frame));
    }

    #[DataProvider('clockCases')]
    public function test_clock_changes_during_consent_read_cannot_change_identity_cutoff(bool $explicit, int $shift): void
    {
        $frame = $explicit ? $this->frame() : null;
        DB::table('identity_verifications')->update(['checked_at' => $shift > 0 ? $this->asOf->addSecond() : $this->asOf]);
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $hit = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$hit, $shift): void {
            if (! $hit && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "consent_records"')) {
                $hit = true;
                $this->travelTo($this->asOf->addSeconds($shift));
            }
        });
        try {
            $this->assertSame($shift < 0, $this->satisfied($frame));
            $this->assertTrue($hit);
        } finally {
            $connection->setEventDispatcher($original);
            $this->travelTo($this->asOf);
        }
    }

    public static function clockCases(): iterable
    {
        foreach ([true, false] as $explicit) {
            foreach ([-2, 2] as $shift) {
                yield ($explicit ? 'frame' : 'legacy')." $shift" => [$explicit, $shift];
            }
        }
    }

    #[DataProvider('badProfiles')]
    public function test_bad_profile_precedes_invalid_document_config(array $changes): void
    {
        DB::table('participants')->where('id', $this->fixture['participant'])->update($changes);
        config()->set('consent.documents', null);
        $this->assertFalse($this->satisfied(null));
        $this->assertFalse($this->satisfied(new AssessmentPrerequisiteFrame($this->asOf, 'UTC', null)));
    }

    public static function badProfiles(): iterable
    {
        yield 'name' => [['full_name' => null]];
        yield 'education' => [['education_level' => ' ']];
        yield 'field' => [['intended_field' => null]];
        yield 'phone' => [['phone' => '']];
        yield 'gender' => [['gender' => null]];
        yield 'birth missing' => [['birth_date' => null]];
        yield 'birth today' => [['birth_date' => '2026-09-04']];
    }

    public function test_legacy_psychotest_rejection_precedes_invalid_dass_config(): void
    {
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['status' => 'declined']);
        config()->set('consent.documents.dass', null);
        $this->assertFalse($this->satisfied(null, 'dass21'));
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['status' => 'accepted']);
        $this->assertTrue($this->satisfied(null, 'ist'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown or invalid consent document [dass].');
        $this->satisfied(null, 'dass21');
    }

    public function test_frame_requires_dass_only_when_applicable(): void
    {
        $frame = $this->frame(dass: false);
        $this->assertTrue($this->satisfied($frame, 'ist'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required consent document [dass].');
        $this->satisfied($frame, 'dass21');
    }

    public function test_explicit_psychotest_rejection_precedes_missing_dass_and_dass_decline_is_type_specific(): void
    {
        $frame = $this->frame(dass: false);
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['status' => 'declined']);
        $this->assertFalse($this->satisfied($frame, 'dass21'));
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['status' => 'accepted']);
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
        $this->assertTrue($this->satisfied($this->frame(), 'ist'));
        $this->assertFalse($this->satisfied($this->frame(), 'dass21'));
    }

    public function test_legacy_server_today_is_fresh_across_midnight(): void
    {
        DB::table('participants')->where('id', $this->fixture['participant'])->update(['birth_date' => '2026-09-04']);
        $this->travelTo($this->asOf->endOfDay());
        $this->assertFalse($this->satisfied(null));
        $this->travelTo($this->asOf->addDay()->startOfDay());
        $this->assertTrue($this->satisfied(null));
    }

    public function test_frame_identity_parsing_does_not_reload_process_timezone(): void
    {
        $frame = $this->frame();
        $reviewer = Admin::create(['name' => 'Synthetic', 'email' => 'zone@example.test', 'password' => 'synthetic', 'role' => AdminRole::SuperAdmin]);
        DB::table('identity_verifications')->update(['outcome' => 'mismatch', 'manual_status' => 'accepted',
            'reviewed_by_admin_id' => $reviewer->id, 'reviewed_at' => $this->asOf]);
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Los_Angeles');
            $this->assertTrue($this->satisfied($frame));
            $this->assertSame('America/Los_Angeles', date_default_timezone_get());
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_missing_psychotest_is_an_explicit_document_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required consent document [psychotest].');
        $this->satisfied(new AssessmentPrerequisiteFrame($this->asOf, 'UTC', null));
    }

    #[DataProvider('mislabeledDocuments')]
    public function test_frame_rejects_contradictory_document_labels(string $psychotest, string $dass): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssessmentPrerequisiteFrame($this->asOf, 'UTC', ConsentDocument::for($psychotest), ConsentDocument::for($dass));
    }

    public static function mislabeledDocuments(): iterable
    {
        yield 'psychotest slot' => ['dass', 'dass'];
        yield 'dass slot' => ['psychotest', 'psychotest'];
    }

    public function test_blank_document_compatibility_is_preserved(): void
    {
        config()->set('consent.documents.psychotest', ['version' => '', 'title' => '', 'text' => '']);
        $frame = $this->frame();
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['document_version' => '', 'document_hash' => hash('sha256', '')]);
        $this->assertTrue($this->satisfied($frame));
        $this->assertTrue($this->satisfied(null));
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['participants', 'consent_records', 'identity_verifications', 'identity_evidence', 'audit_logs', 'outbox_messages'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
