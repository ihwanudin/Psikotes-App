<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Pages\PsychologistReviewFixture;
use App\Models\Admin;
use App\Models\Branch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PsychologistReviewFixturePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_page_is_testing_only_and_requires_both_psychologist_abilities(): void
    {
        $psychologist = $this->admin(AdminRole::Psychologist);
        $this->actingAs($psychologist, 'admin');

        self::assertTrue(PsychologistReviewFixture::isDiscovered());
        self::assertTrue(PsychologistReviewFixture::shouldRegisterNavigation());
        self::assertTrue(PsychologistReviewFixture::canAccess());
        $this->get(PsychologistReviewFixture::getUrl())->assertOk();

        foreach ([AdminRole::SuperAdmin, AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $actor = $this->admin($role);
            $this->actingAs($actor, 'admin');

            self::assertFalse(PsychologistReviewFixture::canAccess());
            Livewire::test(PsychologistReviewFixture::class)->assertNotFound();
        }
    }

    public function test_guest_and_non_psychologist_cannot_open_the_fixture_directly(): void
    {
        $this->get(PsychologistReviewFixture::getUrl())
            ->assertRedirect('/admin/login');

        foreach ([AdminRole::SuperAdmin, AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $this->flushSession();
            $this->actingAs($this->admin($role), 'admin');

            $response = $this->get(PsychologistReviewFixture::getUrl());
            self::assertSame(404, $response->getStatusCode(), $role->value.' '.$response->headers->get('Location'));
        }
    }

    public function test_page_is_not_discovered_or_registered_outside_testing(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');
        app()->detectEnvironment(static fn (): string => 'production');

        self::assertFalse(PsychologistReviewFixture::isDiscovered());
        self::assertFalse(PsychologistReviewFixture::shouldRegisterNavigation());
        self::assertFalse(PsychologistReviewFixture::canAccess());
    }

    public function test_hpp_and_internal_dass_are_distinct_server_rendered_projections(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(PsychologistReviewFixture::class);
        $component->assertSuccessful();
        $component->assertSee('DATA SINTETIS — BUKAN LAPORAN NYATA');
        $component->assertSee('Pratinjau HPP');
        $component->assertSee('Kategori umum DASS-21');
        $component->assertSee('Kondisi dalam batas normal pada skrining ini.');
        $component->assertDontSee('Skor subskala DASS-21');
        $component->assertDontSee('Depresi (x2)');
        $component->assertDontSee('Sumber level psikotes');
        $component->assertDontSee('Ringkasan instrumen sintetis');
        $component->assertDontSee('IQ sintetis 104');

        $component->call('showPanel', 'internal');
        $component->assertSee('Bukti internal psikolog');
        $component->assertSee('Ringkasan instrumen sintetis');
        $component->assertSee('IQ sintetis 104');
        $component->assertSee('Skor subskala DASS-21');
        $component->assertSee('Depresi (x2)');
        $component->assertSee('Sumber level psikotes');
        $component->assertSee('DASS-21 terpisah dan tidak memengaruhi level, zona, atau rekomendasi.');
        $component->assertDontSee('Kondisi dalam batas normal pada skrining ini.');
    }

    public function test_fixture_uses_the_exact_recursive_frozen_view_model_shape(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $fixtureMethod = new \ReflectionMethod(PsychologistReviewFixture::class, 'fixture');
        $fixture = $fixtureMethod->invoke(Livewire::test(PsychologistReviewFixture::class)->instance());

        self::assertSame([
            'fixtureId', 'case', 'participant', 'report', 'validity', 'eligibility', 'hpp', 'internal',
            'reviewDraft', 'signingReadiness',
        ], array_keys($fixture));
        self::assertSame(['publicId', 'origin', 'organizationLabel', 'packageLabel', 'intendedField'], array_keys($fixture['case']));
        self::assertSame(['testNumber', 'displayName'], array_keys($fixture['participant']));
        self::assertSame(['publicId', 'version', 'state', 'expectedSnapshotHash'], array_keys($fixture['report']));
        self::assertSame(['status', 'procedureNote', 'findings'], array_keys($fixture['validity']));
        self::assertSame([
            'publicationBlocked', 'standardVersion', 'sourceVersions', 'iq', 'aspects', 'systemLabel',
            'recalculatedLabel', 'finalLabel', 'labelOverride', 'guardrails',
        ], array_keys($fixture['eligibility']));
        self::assertSame(['ist', 'papi', 'kraepelin', 'rmib', 'reporting'], array_keys($fixture['eligibility']['sourceVersions']));
        self::assertCount(18, $fixture['eligibility']['aspects']);
        self::assertSame([
            'A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
            'D1', 'D2', 'D3', 'D4', 'D5',
        ], array_column($fixture['eligibility']['aspects'], 'code'));

        foreach ($fixture['eligibility']['aspects'] as $aspect) {
            self::assertSame([
                'code', 'label', 'critical', 'systemLevel', 'finalLevel', 'standard', 'zone', 'sources', 'g7', 'override',
            ], array_keys($aspect), $aspect['code']);
            foreach ($aspect['sources'] as $source) {
                self::assertSame(['sourceCode', 'sourceVersion', 'level'], array_keys($source), $aspect['code']);
            }
            self::assertSame(['required', 'state', 'spread', 'reasonCode', 'finalLevel', 'reason'], array_keys($aspect['g7']), $aspect['code']);
            self::assertSame(['changed', 'reason'], array_keys($aspect['override']), $aspect['code']);
        }

        self::assertSame(['changed', 'reason'], array_keys($fixture['eligibility']['labelOverride']));
        self::assertSame(['clusters', 'generalDass'], array_keys($fixture['hpp']));
        self::assertSame(['A', 'B', 'C', 'D'], array_keys($fixture['hpp']['clusters']));
        self::assertSame(['category', 'narrativeId', 'narrativeJp'], array_keys($fixture['hpp']['generalDass']));
        self::assertSame(['instrumentSummaries', 'integrationSlots', 'dass'], array_keys($fixture['internal']));
        foreach ($fixture['internal']['instrumentSummaries'] as $summary) {
            self::assertSame(['instrument', 'summary'], array_keys($summary));
        }
        self::assertSame(['authorityReady', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7'], array_keys($fixture['internal']['integrationSlots']));
        self::assertSame(['subscales', 'generalCategory', 'followUp', 'flags'], array_keys($fixture['internal']['dass']));
        foreach ($fixture['internal']['dass']['subscales'] as $subscale) {
            self::assertSame(['label', 'score', 'category'], array_keys($subscale));
        }
        self::assertSame(['procedureNote', 'accompanimentConditions', 'clusters'], array_keys($fixture['reviewDraft']));
        self::assertSame(['A', 'B', 'C', 'D'], array_keys($fixture['reviewDraft']['clusters']));

        foreach (['A', 'B', 'C', 'D'] as $cluster) {
            self::assertSame(['id', 'jp'], array_keys($fixture['hpp']['clusters'][$cluster]));
            self::assertSame(['id', 'jp'], array_keys($fixture['reviewDraft']['clusters'][$cluster]));
        }

        self::assertSame(['canSign', 'blockerCodes', 'persistenceAuthorityBound'], array_keys($fixture['signingReadiness']));
        self::assertFalse($fixture['signingReadiness']['canSign']);
        self::assertFalse($fixture['signingReadiness']['persistenceAuthorityBound']);
        self::assertSame(['ACCOMPANIMENT_CONDITIONS_REQUIRED', 'G7_ASPECTS_UNRESOLVED', 'PERSISTENCE_AUTHORITY_UNBOUND'], $fixture['signingReadiness']['blockerCodes']);

        $v3 = $fixtureMethod->invoke(Livewire::test(PsychologistReviewFixture::class, ['scenario' => 'v3'])->instance());
        self::assertTrue($v3['eligibility']['publicationBlocked']);
        self::assertNull($v3['eligibility']['systemLabel']);
        self::assertNull($v3['eligibility']['recalculatedLabel']);
        self::assertNull($v3['eligibility']['finalLabel']);
        self::assertSame(['changed' => false, 'reason' => null], $v3['eligibility']['labelOverride']);
        self::assertFalse($v3['signingReadiness']['canSign']);
        self::assertFalse($v3['signingReadiness']['persistenceAuthorityBound']);
        self::assertSame(['VALIDITY_V3', 'PERSISTENCE_AUTHORITY_UNBOUND'], $v3['signingReadiness']['blockerCodes']);
        self::assertNull($v3['reviewDraft']['procedureNote']);
        self::assertNull($v3['reviewDraft']['accompanimentConditions']);
        foreach ($v3['eligibility']['aspects'] as $aspect) {
            self::assertSame(['changed' => false, 'reason' => null], $aspect['override']);
            self::assertNull($aspect['g7']['finalLevel']);
            self::assertNull($aspect['g7']['reason']);
        }
    }

    public function test_g6_reason_and_g7_resolution_are_validated_without_enabling_signing(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(PsychologistReviewFixture::class);
        $component->call('showPanel', 'internal');
        $component->call('validateDraft');
        $component->assertSet('blockingCodes', [
            'ACCOMPANIMENT_CONDITIONS_REQUIRED',
            'G7_ASPECTS_UNRESOLVED',
            'PERSISTENCE_AUTHORITY_UNBOUND',
        ]);
        $component->set('accompanimentConditions', 'Pendampingan adaptasi kerja selama masa awal.');
        $component->set('g6FinalLevel', 4);
        $component->set('g6Reason', '1234567890123456789');
        $component->set('g7FinalLevel', 3);
        $component->call('validateDraft');
        $component->assertSet('blockingCodes', [
            'OVERRIDE_REASON_MIN_LENGTH',
            'PERSISTENCE_AUTHORITY_UNBOUND',
        ]);
        $component->set('g6Reason', '12345678901234567890');
        $component->call('validateDraft');
        $component->assertSet('blockingCodes', ['PERSISTENCE_AUTHORITY_UNBOUND']);
        $component->assertSee('Belum dapat ditandatangani');
        $component->assertSeeHtml('data-signing-enabled="false"');
        $component->assertSeeHtml('disabled');

        self::assertSame(20, mb_strlen($component->get('g6Reason')));
    }

    public function test_level_change_invalidates_then_server_recomputes_the_recommendation_preview(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(PsychologistReviewFixture::class)
            ->call('showPanel', 'internal')
            ->assertSet('previewInvalidated', false)
            ->set('g6FinalLevel', 4)
            ->assertSet('previewInvalidated', true)
            ->assertSee('Pratinjau rekomendasi menunggu hitung ulang server')
            ->set('g6Reason', 'Alasan profesional untuk menaikkan level aspek.')
            ->set('g7FinalLevel', 3)
            ->set('accompanimentConditions', 'Pendampingan adaptasi kerja selama masa awal.')
            ->call('validateDraft')
            ->assertSet('previewInvalidated', false)
            ->assertSet('previewFinalLabel', 'DIPERTIMBANGKAN')
            ->assertSee('Hasil hitung ulang server: DIPERTIMBANGKAN');

        self::assertNotSame('TIDAK_DISARANKAN', $component->get('previewFinalLabel'));
    }

    public function test_label_override_and_all_policy_blockers_use_the_frozen_domain_rules(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        Livewire::test(PsychologistReviewFixture::class, ['scenario' => 'v2'])
            ->call('showPanel', 'internal')
            ->assertSet('blockingCodes', [
                'V2_PROCEDURE_NOTE_REQUIRED',
                'ACCOMPANIMENT_CONDITIONS_REQUIRED',
                'G7_ASPECTS_UNRESOLVED',
                'PERSISTENCE_AUTHORITY_UNBOUND',
            ])
            ->set('labelFinal', 'TIDAK_DISARANKAN')
            ->set('labelReason', '1234567890123456789')
            ->set('g7FinalLevel', 3)
            ->call('validateDraft')
            ->assertSet('blockingCodes', [
                'V2_PROCEDURE_NOTE_REQUIRED',
                'OVERRIDE_REASON_MIN_LENGTH',
                'PERSISTENCE_AUTHORITY_UNBOUND',
            ])
            ->set('procedureNote', 'Gangguan koneksi telah ditinjau oleh psikolog.')
            ->set('accompanimentConditions', 'Pendampingan adaptasi kerja selama masa awal.')
            ->set('labelReason', 'Pertimbangan profesional menunjukkan risiko tambahan.')
            ->set('clusterDrafts.A', '   ')
            ->call('validateDraft')
            ->assertSet('blockingCodes', [
                'NARRATIVE_CLUSTER_A_REQUIRED',
                'PERSISTENCE_AUTHORITY_UNBOUND',
            ])
            ->assertHasErrors(['clusterDrafts.A']);
    }

    public function test_blocker_focus_queue_and_temporary_draft_controls_are_accessible(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(PsychologistReviewFixture::class, ['scenario' => 'v2'])
            ->call('showPanel', 'internal')
            ->assertSee('Perubahan draf akan hilang jika halaman dimuat ulang')
            ->assertSeeHtml('name="procedure_note"')
            ->assertSeeHtml('autocomplete="off"');

        $html = $component->html();
        $queue = strpos($html, 'Antrean tinjauan G7');
        $aspect = strpos($html, 'C4 — Stres dan stabilitas');
        $table = strpos($html, 'Sumber level psikotes</caption>');
        self::assertIsInt($queue);
        self::assertIsInt($aspect);
        self::assertIsInt($table);
        self::assertTrue($queue < $aspect && $aspect < $table);

        $component->call('focusBlocker', 'V2_PROCEDURE_NOTE_REQUIRED')
            ->assertDispatched('review-focus', target: 'procedure-note')
            ->call('showPanel', 'hpp')
            ->assertDispatched('review-focus', target: 'projection-heading');
    }

    public function test_missing_field_and_all_blank_narrative_clusters_map_to_stable_blockers(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        Livewire::test(PsychologistReviewFixture::class, ['scenario' => 'missing-field'])
            ->set('accompanimentConditions', 'Pendampingan adaptasi kerja selama masa awal.')
            ->set('g7FinalLevel', 3)
            ->set('clusterDrafts', ['A' => ' ', 'B' => "\n", 'C' => '', 'D' => "\t"])
            ->call('validateDraft')
            ->assertSet('blockingCodes', [
                'TARGET_FIELD_REQUIRED',
                'NARRATIVE_CLUSTER_A_REQUIRED',
                'NARRATIVE_CLUSTER_B_REQUIRED',
                'NARRATIVE_CLUSTER_C_REQUIRED',
                'NARRATIVE_CLUSTER_D_REQUIRED',
                'PERSISTENCE_AUTHORITY_UNBOUND',
            ])
            ->assertHasErrors([
                'targetField',
                'clusterDrafts.A',
                'clusterDrafts.B',
                'clusterDrafts.C',
                'clusterDrafts.D',
            ]);
    }

    public function test_changed_g7_level_reuses_the_unicode_aware_g6_reason_rule(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        Livewire::test(PsychologistReviewFixture::class)
            ->set('accompanimentConditions', 'Pendampingan adaptasi kerja selama masa awal.')
            ->set('g7FinalLevel', 4)
            ->set('g7Reason', '短い理由')
            ->call('validateDraft')
            ->assertSet('blockingCodes', [
                'OVERRIDE_REASON_MIN_LENGTH',
                'PERSISTENCE_AUTHORITY_UNBOUND',
            ])
            ->set('g7Reason', '心理学的判断として十分な根拠をここに記録します。')
            ->call('validateDraft')
            ->assertSet('blockingCodes', ['PERSISTENCE_AUTHORITY_UNBOUND']);
    }

    public function test_v3_fixture_stops_review_and_has_no_recommendation_or_actions(): void
    {
        $this->actingAs($this->admin(AdminRole::Psychologist), 'admin');

        $component = Livewire::test(PsychologistReviewFixture::class, ['scenario' => 'v3']);
        $component->assertSuccessful();
        $component->assertSet('blockingCodes', ['VALIDITY_V3', 'PERSISTENCE_AUTHORITY_UNBOUND']);
        $component->assertSee('STOP — laporan tidak dibuat');
        $component->assertSee('Jadwalkan ulang asesmen.');
        $component->assertDontSee('Ubah level profesional');
        $component->assertDontSee('DISARANKAN');
        $component->assertDontSee('DIPERTIMBANGKAN');
        $component->assertDontSee('TIDAK_DISARANKAN');
        $component->assertSeeHtml('data-validity-stop="V3"');
    }

    public function test_every_livewire_action_rechecks_revoked_reviewer_access(): void
    {
        $psychologist = $this->admin(AdminRole::Psychologist);
        $this->actingAs($psychologist, 'admin');
        $component = Livewire::test(PsychologistReviewFixture::class);
        $component->assertSuccessful();

        Admin::query()->whereKey($psychologist->id)->update(['role' => AdminRole::Staff->value]);

        $component->call('showPanel', 'internal')->assertNotFound();
    }

    private function admin(AdminRole $role): Admin
    {
        $branchId = null;
        if (in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)) {
            $branchId = Branch::query()->create([
                'code' => 'BR-'.strtoupper(substr($role->value, 0, 3)).'-'.uniqid(),
                'name' => 'Synthetic branch',
                'ref_code' => 'REF-'.strtoupper(substr($role->value, 0, 3)).'-'.uniqid(),
            ])->id;
        }

        return Admin::query()->create([
            'branch_id' => $branchId,
            'name' => 'Synthetic '.str_replace('_', ' ', $role->value),
            'email' => uniqid($role->value.'-', true).'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
            'can_verify_payments' => false,
        ]);
    }
}
