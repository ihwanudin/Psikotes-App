<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentResults\SealedGenericAnswerSet;
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
 * Proof-of-defect test for the F2 lane checksum bug (Lead-approved increment 3b,
 * 2026-09-20). `instrument_versions.payload` is `jsonb`
 * (database/migrations/2026_08_23_000000_create_instrument_versions_table.php:19).
 * PostgreSQL normalizes jsonb on write (whitespace and key order), so the bytes
 * `ScoreSealedIstAnswerSet` reads back from `payload` are not the exact bytes
 * `InstrumentSeeder` hashed into `checksum`
 * (database/seeders/InstrumentSeeder.php:40-43). `ScoreSealedIstAnswerSet::scoringData()`
 * re-hashes the returned payload and rejects any mismatch
 * (app/Services/AssessmentResults/ScoreSealedIstAnswerSet.php:129), so scoring a
 * real seeded IST row fails closed with `SEALED_IST_RESULT_INVALID` in
 * PostgreSQL even though the seeded content and the submitted answers are both
 * entirely correct. SQLite cannot reproduce this: its `jsonb` column is stored
 * as plain text, so the round trip is byte-identical there and the existing
 * SQLite feature tests (tests/Feature/AssessmentResults/ScoreSealedIstAnswerSetTest.php)
 * never exercise it — they also insert a hand-crafted row instead of running the
 * real seeder.
 *
 * This test intentionally asserts the CURRENT BROKEN behavior (the scorer
 * throws). Once the F2 lane's 3a schema change lands (a dedicated `source_text`
 * column hashed instead of the jsonb round trip), this test must be flipped in
 * the SAME commit to assert successful scoring, exactly like the required
 * treatment of tests/Postgres/SignedReportDatasetRlsTest.php's
 * test_jsonb_payload_is_readable_but_not_byte_identical.
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

    public function test_scoring_a_really_seeded_ist_row_fails_closed_on_jsonb_checksum_mismatch(): void
    {
        (new InstrumentSeeder)->run();

        [$scoringSourceId, $storedChecksum, $storedPayload] = app(RlsContextRunner::class)->runAsService(
            static fn (): array => (function (): array {
                $row = DB::table('instrument_versions')
                    ->where('code', 'ist')
                    ->where('is_active', true)
                    ->select(['id', 'checksum', 'payload'])
                    ->first();
                if ($row === null) {
                    throw new RuntimeException('InstrumentSeeder did not activate an IST row.');
                }

                return [(int) $row->id, (string) $row->checksum, (string) $row->payload];
            })(),
        );

        // Control assertion: the checksum column itself is correct (it was computed
        // from the raw file bytes before insert, not re-derived), and it matches the
        // raw source file on disk exactly. The defect is entirely in re-hashing the
        // jsonb round trip, not in what InstrumentSeeder stored.
        $this->assertSame(hash_file('sha256', database_path('seeders/data/ist.json')), $storedChecksum);
        $this->assertFalse(
            hash_equals($storedChecksum, hash('sha256', $storedPayload)),
            'PostgreSQL jsonb normalization was expected to break the byte-identical round trip; '
            .'if this assertion fails, the underlying PostgreSQL behavior changed and this whole '
            .'proof-of-defect test needs re-evaluation, not deletion.',
        );

        $source = $this->realIstSource();

        try {
            app(RlsContextRunner::class)->runAsService(
                fn (): mixed => app(ScoreSealedIstAnswerSet::class)->execute($source, $scoringSourceId),
            );
            $this->fail(
                'ScoreSealedIstAnswerSet was expected to fail closed on the jsonb checksum mismatch. '
                .'If the checksum bug has been fixed (F2 lane increment 3a), update this test to assert '
                .'successful scoring instead of deleting it.',
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
        foreach (['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'] as $code) {
            $count = count($keys[$code]);
            $subtests[] = ['code' => $code, 'duration_seconds' => 60, 'item_count' => $count];
            for ($item = 1; $item <= $count; $item++) {
                // The submitted answers are exactly correct; only the stored source's
                // checksum re-derivation is under test.
                $values[] = $keys[$code][$item] === '__unknown__' ? 'any-answer' : $keys[$code][$item];
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
