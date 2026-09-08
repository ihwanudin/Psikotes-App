<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssessmentSessionMigrationDefinitionTest extends TestCase
{
    private const string MIGRATION = __DIR__.'/../../../database/migrations/2026_09_08_000100_create_generic_assessment_sessions.php';

    #[Test]
    public function it_declares_the_frozen_postgresql_schema_contract_without_rls(): void
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
        self::assertStringContainsString('restrictOnDelete()', $sql);
        self::assertStringNotContainsString('ENABLE ROW LEVEL SECURITY', $sql);
        self::assertStringNotContainsString('CREATE POLICY', $sql);
    }
}
