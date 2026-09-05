<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

/** P17a blocker probe: do not weaken this expected privacy boundary. */
final class DassConsentBranchPrivacyTest extends TestCase
{
    /** @var array{organization:int,participant:int,firstAttempt:int,secondAttempt:int,foreignOrganization:int} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $this->fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $first = Fixture::create();
            $second = Fixture::create([
                'organization' => $first['organization'], 'participant' => $first['participant'],
            ]);
            $foreign = Fixture::create();
            foreach ([$first, $second] as $fixture) {
                DB::table('package_items')->insert([
                    'package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2,
                ]);
            }
            DB::table('participants')->where('id', $first['participant'])->update([
                'full_name' => 'PRIVATE DASS CONSENT PROFILE',
            ]);
            $document = ConsentDocument::for('dass');
            $consent = DB::table('consent_records')->insertGetId([
                'participant_id' => $first['participant'], 'consent_type' => 'dass',
                'status' => 'accepted', 'document_version' => $document->version,
                'document_hash' => $document->hash, 'consented_at' => now(),
            ]);
            $assessment = DB::table('dass.assessments')->insertGetId([
                'public_id' => (string) Str::ulid(), 'participant_id' => $first['participant'],
                'consent_record_id' => $consent, 'status' => 'completed',
                'started_at' => now()->subMinute(), 'completed_at' => now(),
                'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('dass.responses')->insert([
                'assessment_id' => $assessment, 'item_number' => 1, 'response_value' => 3,
                'response_time_ms' => 321, 'answered_at' => now(), 'expires_at' => now()->addYear(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('dass.results')->insert([
                'assessment_id' => $assessment,
                'depression_raw' => 1, 'anxiety_raw' => 2, 'stress_raw' => 3,
                'depression_score' => 2, 'anxiety_score' => 4, 'stress_score' => 6,
                'depression_category' => 'PRIVATE-DEP', 'anxiety_category' => 'PRIVATE-ANX',
                'stress_category' => 'PRIVATE-STRESS', 'overall_category' => 'PRIVATE-OVERALL',
                'follow_up' => 'PRIVATE-FOLLOWUP',
                'validity_flags' => json_encode(['marker' => 'PRIVATE-DASS-RESULT'], JSON_THROW_ON_ERROR),
                'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return [
                'organization' => (int) $first['organization'],
                'participant' => (int) $first['participant'],
                'firstAttempt' => (int) $first['attempt'],
                'secondAttempt' => (int) $second['attempt'],
                'foreignOrganization' => (int) $foreign['organization'],
            ];
        });
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_branch_admin_cannot_read_dass_or_dass_consent_detail_for_two_attempt_participant(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertNotSame($this->fixture['firstAttempt'], $this->fixture['secondAttempt']);

        $runner = app(RlsContextRunner::class);
        $runner->run(new RlsContext('branch_admin', $this->fixture['foreignOrganization']), function (): void {
            foreach (['dass.assessments', 'dass.responses', 'dass.results', 'consent_records'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'Foreign branch leaked '.$table);
            }
        });
        $runner->run(new RlsContext('branch_admin', $this->fixture['organization']), function (): void {
            foreach (['dass.assessments', 'dass.responses', 'dass.results'] as $table) {
                $this->assertSame(0, DB::table($table)->count(), 'Branch admin leaked '.$table);
            }
            $details = DB::table('consent_records')
                ->where('participant_id', $this->fixture['participant'])
                ->where('consent_type', 'dass')
                ->get(['status', 'document_version', 'document_hash', 'consented_at', 'withdrawn_at']);
            $this->assertCount(
                0,
                $details,
                'Branch admin can read DASS consent status/version/hash/timestamps; P17a requires this detail hidden.',
            );
        });
    }
}
