<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
use App\Domain\AssessmentResults\SealedIstResult;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use App\Services\AssessmentResults\ScoreSealedIstAnswerSet;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\GenericResultLedgerMigrationFixture;
use UnexpectedValueException;

/**
 * F2 lane checksum defect (identified 2026-09-20) and its fix (increment 3a,
 * same day). `instrument_versions.payload` is `jsonb`
 * (database/migrations/2026_08_23_000000_create_instrument_versions_table.php:19).
 * PostgreSQL normalizes jsonb on write (whitespace and key order), so the bytes
 * read back from `payload` were never the bytes `InstrumentSeeder` hashed into
 * `checksum` (database/seeders/InstrumentSeeder.php:40-43), and
 * `ScoreSealedIstAnswerSet` rejected every real seeded IST row in PostgreSQL
 * with `SEALED_IST_RESULT_INVALID`
 * (app/Services/AssessmentResults/ScoreSealedIstAnswerSet.php:129) — proven by
 * this file's first test before the fix (SHA `663ffaa`).
 *
 * The fix (migration `2026_09_20_000100_add_source_text_to_instrument_versions`)
 * adds `source_text`: the exact raw bytes the checksum was computed from,
 * stored verbatim and locked by the history-immutability trigger alongside
 * `payload`/`checksum`. The scorer now hashes `source_text`, never `payload`,
 * with no fallback. This test now asserts the FIXED behavior (successful
 * scoring); the first test's original assertion — that scoring fails — was the
 * proof-of-defect and has been deliberately inverted in this same commit,
 * exactly as required.
 */
final class InstrumentChecksumScoringIntegrityTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->asOwner(function (): void {
            DB::statement('TRUNCATE TABLE instrument_versions RESTART IDENTITY');
        });

        parent::tearDown();
    }

    public function test_scoring_a_really_seeded_ist_row_succeeds_in_postgresql(): void
    {
        (new InstrumentSeeder)->run();

        [$scoringSourceId, $storedChecksum, $storedPayload, $storedSourceText] = app(RlsContextRunner::class)->runAsService(
            static fn (): array => (function (): array {
                $row = DB::table('instrument_versions')
                    ->where('code', 'ist')
                    ->where('is_active', true)
                    ->select(['id', 'checksum', 'payload', 'source_text'])
                    ->first();
                if ($row === null) {
                    throw new RuntimeException('InstrumentSeeder did not activate an IST row.');
                }

                return [(int) $row->id, (string) $row->checksum, (string) $row->payload, (string) $row->source_text];
            })(),
        );

        // Control assertions, unchanged from the original proof-of-defect: the
        // checksum column is correct, and PostgreSQL still normalizes jsonb (the
        // underlying PostgreSQL behavior this fix works around has not changed;
        // only which column the scorer trusts has).
        $this->assertSame(hash_file('sha256', database_path('seeders/data/ist.json')), $storedChecksum);
        $this->assertFalse(
            hash_equals($storedChecksum, hash('sha256', $storedPayload)),
            'PostgreSQL jsonb normalization was expected to still break the payload round trip; '
            .'if this assertion fails, the underlying PostgreSQL behavior changed and this test '
            .'needs re-evaluation.',
        );
        // The fix: source_text IS byte-identical to the checksum's source, so it
        // DOES hash back correctly, unlike payload above.
        $this->assertSame(file_get_contents(database_path('seeders/data/ist.json')), $storedSourceText);
        $this->assertTrue(hash_equals($storedChecksum, hash('sha256', $storedSourceText)));

        $source = $this->realIstSource();

        $result = app(RlsContextRunner::class)->runAsService(
            fn (): SealedIstResult => app(ScoreSealedIstAnswerSet::class)->execute($source, $scoringSourceId),
        );

        $this->assertInstanceOf(SealedIstResult::class, $result);
        $this->assertSame($scoringSourceId, $result->scoringSource['id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $result->resultChecksum);
    }

    /**
     * Lead-required guard (2026-09-20): a row with a NULL source_text must fail
     * closed, and specifically must NOT silently fall back to re-deriving
     * checksum verification from payload. This is the regression guard that
     * makes it safe for source_text to stay nullable — without it, someone
     * could reintroduce a `payload` fallback later and the original bug would
     * return invisibly.
     */
    public function test_scoring_fails_closed_when_source_text_is_null_even_though_payload_checksum_matches(): void
    {
        (new InstrumentSeeder)->run();

        $realPayload = file_get_contents(database_path('seeders/data/ist.json'));
        if ($realPayload === false) {
            throw new RuntimeException('Canonical IST scoring data could not be read.');
        }

        // A row whose payload's checksum WOULD verify correctly if the scorer
        // ever fell back to hashing payload — but source_text is NULL. Inserted
        // directly (not through InstrumentSeeder) to construct exactly this
        // otherwise-impossible-via-the-seeder state. INSERT is unrestricted by
        // the history trigger's source_text rule (only UPDATE/deactivation is
        // constrained), so this requires no trigger manipulation.
        $scoringSourceId = app(RlsContextRunner::class)->runAsService(
            static fn (): int => DB::table('instrument_versions')->insertGetId([
                'code' => 'ist',
                'version' => 'null-source-text-proof-v1',
                'source_file' => 'ist.json',
                'checksum' => hash('sha256', $realPayload),
                'payload' => $realPayload,
                'source_text' => null,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        );

        $source = $this->realIstSource();

        try {
            app(RlsContextRunner::class)->runAsService(
                fn (): mixed => app(ScoreSealedIstAnswerSet::class)->execute($source, $scoringSourceId),
            );
            $this->fail(
                'A NULL source_text must fail closed. If this passed, the scorer is silently falling '
                .'back to payload, and the original PostgreSQL jsonb checksum bug is back.',
            );
        } catch (UnexpectedValueException $exception) {
            $this->assertSame('SEALED_IST_RESULT_INVALID', $exception->getMessage());
        }
    }

    private function realIstSource(): SealedGenericAnswerSet
    {
        $data = $this->canonicalIstData();
        $keys = [];
        foreach ($data['keys'] as $key) {
            $keys[$key['subtest']][$key['item']] = $key['key'];
        }
        foreach ($data['ge_dictionary'] as $definition) {
            $keys['GE'][$definition['item']] = '__unknown__';
        }

        $subtests = [];
        $values = [];
        // Mirrors the accepted SQLite fixture in ScoreSealedIstAnswerSetTest::istSource():
        // only these five subtests are answered with their exact real key so the raw
        // scoring pipeline (norms domain, standard score lookup, IQ bands) computes
        // deterministically; GE/FA/WU/ME are intentionally answered "wrong" here too,
        // for the same reason that test does it — this test proves the checksum path,
        // not full-marks scoring, and an all-subtests-correct combination is untested
        // territory for the norms tables that this test has no need to exercise.
        $correctSubtests = ['SE', 'WA', 'AN', 'RA', 'ZR'];
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $code) {
            $count = count($keys[$code]);
            $subtests[] = ['code' => $code, 'duration_seconds' => 60, 'item_count' => $count];
            for ($item = 1; $item <= $count; $item++) {
                $values[] = in_array($code, $correctSubtests, true) ? $keys[$code][$item] : '__wrong__';
            }
        }

        $definitionSource = [
            'instrument' => GenericAssessmentInstrument::Ist->value,
            'version' => 'pg-proof-definition-v1',
            'provenance' => 'pg-proof-test-only',
            'total_duration_seconds' => array_sum(array_column($subtests, 'duration_seconds')),
            'subtests' => $subtests,
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource,
            'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        $answers = [];
        foreach ($values as $offset => $value) {
            $answers[] = [
                'item_no' => $offset + 1,
                'value' => $value,
                'revision' => 1,
                'answered_at' => '2026-09-20T03:10:00.123456Z',
            ];
        }

        return SealedGenericAnswerSet::seal(
            assessmentCaseId: 11,
            sessionId: 22,
            participantId: 33,
            sessionPublicId: '01K50PGPROOFISTSESSION00000',
            instrument: GenericAssessmentInstrument::Ist,
            attemptNo: 1,
            submittedAt: '2026-09-20T03:20:00.654321Z',
            answersRevision: 1,
            definition: $definition,
            answers: $answers,
        );
    }

    /** @return array<string, mixed> */
    private function canonicalIstData(): array
    {
        $payload = file_get_contents(database_path('seeders/data/ist.json'));
        if (! is_string($payload)) {
            throw new RuntimeException('Canonical IST scoring data could not be read.');
        }
        $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Canonical IST scoring data is invalid.');
        }

        return $data;
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        if ($runtime === 'instrument_checksum_proof_owner') {
            GenericResultLedgerMigrationFixture::withoutLedger(fn (): mixed => $callback(DB::connection()));

            return;
        }
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.instrument_checksum_proof_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('instrument_checksum_proof_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $owner = DB::connection();
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            GenericResultLedgerMigrationFixture::withoutLedger(fn (): mixed => $callback($owner));
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('instrument_checksum_proof_owner');
            config()->set('database.connections.instrument_checksum_proof_owner', null);
        }
    }
}
