<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionMigrationDefinitionTest extends TestCase
{
    private const string MIGRATION = __DIR__.'/../../../database/migrations/2026_09_08_000100_create_generic_assessment_sessions.php';

    #[Test]
    public function it_declares_the_frozen_postgresql_schema_and_rls_contract(): void
    {
        $sql = file_get_contents(self::MIGRATION);

        self::assertIsString($sql);
        self::assertStringContainsString("Schema::create('test_sessions'", $sql);
        self::assertStringContainsString("Schema::create('answers'", $sql);
        self::assertStringContainsString("Schema::create('assessment_autosave_mutations'", $sql);
        self::assertStringContainsString("test_type IN ('ist','papi','rmib','kraepelin')", $sql);
        self::assertStringNotContainsString("'dass21'", $sql);
        self::assertStringContainsString("status IN ('created','in_progress','submitted','scored','expired','void')", $sql);
        self::assertStringContainsString('submitted_at BETWEEN started_at AND ends_at', $sql);
        self::assertStringContainsString('expired_at > ends_at', $sql);
        self::assertStringContainsString("WHERE status IN ('created','in_progress')", $sql);
        self::assertStringContainsString('test_sessions_one_active_attempt_unique', $sql);
        self::assertStringContainsString('assessment_autosave_mutations_session_mutation_unique', $sql);
        self::assertStringContainsString('assessment_autosave_mutations_session_revision_unique', $sql);
        self::assertStringContainsString('test_sessions_identity_revision_guard', $sql);
        self::assertStringContainsString("OLD.status = 'submitted' AND NEW.status IN ('scored','void')", $sql);
        self::assertStringContainsString("OLD.status = 'expired' AND NEW.status = 'void'", $sql);
        self::assertStringContainsString("OLD.status = 'created' AND NEW.status = 'in_progress'", $sql);
        self::assertStringContainsString("OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void')", $sql);
        self::assertStringContainsString('NEW.ends_at IS DISTINCT FROM OLD.ends_at', $sql);
        self::assertStringContainsString('NEW.submitted_at IS DISTINCT FROM OLD.submitted_at', $sql);
        self::assertStringContainsString('NEW.expired_at IS DISTINCT FROM OLD.expired_at', $sql);
        self::assertStringContainsString('answers_identity_revision_guard', $sql);
        self::assertStringContainsString('NEW.revision <= OLD.revision', $sql);
        self::assertStringContainsString('assessment_autosave_mutations_append_only', $sql);
        self::assertStringContainsString('assessment_autosave_mutations_parent_guard', $sql);
        self::assertStringContainsString('NEW.received_at <= session.ends_at', $sql);
        self::assertStringContainsString('restrictOnDelete()', $sql);
        self::assertStringContainsString("timestampTz('ends_at', 6)", $sql);
        self::assertStringContainsString("timestampTz('received_at', 6)", $sql);
        self::assertStringContainsString('submitted_at IS NOT NULL', $sql);
        self::assertStringContainsString('scored_at IS NOT NULL', $sql);
        self::assertStringContainsString('expired_at IS NOT NULL', $sql);
        self::assertStringContainsString('void_reason IS NOT NULL', $sql);
        self::assertSame(3, substr_count($sql, 'ENABLE ROW LEVEL SECURITY'));
        self::assertSame(3, substr_count($sql, 'FORCE ROW LEVEL SECURITY'));
        self::assertStringContainsString('CREATE POLICY test_sessions_participant_update', $sql);
        self::assertStringNotContainsString('test_sessions_participant_update ON test_sessions FOR ALL', $sql);
        self::assertStringContainsString('CREATE POLICY answers_service_insert', $sql);
        self::assertStringContainsString('CREATE POLICY assessment_autosave_mutations_service_insert', $sql);
        self::assertStringNotContainsString('CREATE POLICY assessment_autosave_mutations_service_delete', $sql);
        self::assertStringNotContainsString('GRANT SELECT, INSERT, DELETE ON assessment_autosave_mutations', $sql);
        self::assertStringContainsString('NEW.answers_revision <> OLD.answers_revision + 1', $sql);
        self::assertStringContainsString('mutation.revision = NEW.answers_revision', $sql);
        self::assertStringContainsString('LOCK TABLE test_sessions, answers, assessment_autosave_mutations', $sql);
        self::assertStringContainsString('IN ACCESS EXCLUSIVE MODE', $sql);
    }
}
