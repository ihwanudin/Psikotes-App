<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

final class GenericAssessmentResultVersionsSchemaTest extends TestCase
{
    public function test_primary_unique_owner_and_deferred_self_reference_are_valid_in_catalog(): void
    {
        $constraints = collect(DB::select(<<<'SQL'
            SELECT conname, contype, confdeltype,
                   conrelid::regclass::text AS relation_name,
                   CASE WHEN confrelid = 0 THEN NULL ELSE confrelid::regclass::text END AS referenced_relation,
                   pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE conrelid = 'generic_assessment_result_versions'::regclass
            ORDER BY conname
        SQL))->keyBy('conname');

        $primary = $constraints->get('generic_assessment_result_versions_pkey');
        $this->assertNotNull($primary);
        $this->assertSame('p', $primary->contype);
        $this->assertStringContainsString('PRIMARY KEY (id)', $primary->definition);
        $supersedesUnique = $constraints->get('generic_result_supersedes_unique');
        $this->assertNotNull($supersedesUnique);
        $this->assertSame('u', $supersedesUnique->contype);
        $this->assertStringContainsString('UNIQUE (supersedes_id)', $supersedesUnique->definition);
        $owner = $constraints->get('generic_result_attempt_owner_fk');
        $this->assertNotNull($owner);
        $this->assertSame('f', $owner->contype);
        $this->assertSame('assessment_participants', $owner->referenced_relation);
        $this->assertStringContainsString(
            'FOREIGN KEY (assessment_participant_id, assessment_attempt_id)',
            $owner->definition,
        );
        $self = $constraints->get('generic_result_supersedes_fk');
        $this->assertNotNull($self);
        $this->assertSame('f', $self->contype);
        $this->assertSame('generic_assessment_result_versions', $self->relation_name);
        $this->assertSame('generic_assessment_result_versions', $self->referenced_relation);
        $this->assertSame('r', $self->confdeltype);
        $this->assertStringContainsString('FOREIGN KEY (supersedes_id) REFERENCES', $self->definition);
        $this->assertStringContainsString('(id) ON DELETE RESTRICT', $self->definition);
    }
}
