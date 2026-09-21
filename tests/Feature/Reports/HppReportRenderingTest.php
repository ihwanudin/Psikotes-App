<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Services\ReportRendering\BladeReportRenderer;
use App\Services\ReportRendering\FixtureReportDataset;
use PHPUnit\Framework\Attributes\Depends;
use Tests\TestCase;

final class HppReportRenderingTest extends TestCase
{
    private BladeReportRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = BladeReportRenderer::make();
    }

    public function test_hpp_template_renders_the_full_fixture_story(): string
    {
        $html = $this->renderer->renderHpp(FixtureReportDataset::hppDraft());

        $this->assertStringContainsString('Laporan Hasil Pemeriksaan Psikologis', $html);
        $this->assertStringContainsString('Siti Rahmawati', $html);
        $this->assertStringContainsString('Cukup Baik', $html);
        $this->assertStringContainsString('DIPERTIMBANGKAN', $html);
        $this->assertStringContainsString('Syarat pendampingan', $html);
        $this->assertStringContainsString('Ringan', $html);

        return $html;
    }

    #[Depends('test_hpp_template_renders_the_full_fixture_story')]
    public function test_hpp_template_shades_eighteen_aspect_rows(string $html): void
    {
        $this->assertSame(18, substr_count($html, '●'));
        $this->assertSame(66, substr_count($html, 'zone-ok'));
        $this->assertSame(6, substr_count($html, 'zone-grey'));
    }

    public function test_hpp_template_never_prints_internal_only_material(): void
    {
        $html = $this->renderer->renderHpp(FixtureReportDataset::hppDraft());

        $this->assertStringNotContainsString('Subskala', $html);
        $this->assertStringNotContainsString('Depresi', $html);
        $this->assertStringNotContainsString('INTEGRASI', $html);
        $this->assertStringNotContainsString('Validitas Sesi', $html);
        $this->assertStringNotContainsString('Kraepelin', $html);
        $this->assertStringNotContainsString('PAPI', $html);
        $this->assertStringNotContainsString('RMIB', $html);

        foreach (['A1', 'A2', 'B1', 'B2', 'C2', 'C4', 'C5', 'D4'] as $aspectCode) {
            $this->assertStringNotContainsString($aspectCode, $html);
        }
    }

    public function test_optional_psychologist_block_appears_only_when_filled(): void
    {
        $unsigned = $this->renderer->renderHpp(FixtureReportDataset::hppDraft());
        $signed = $this->renderer->renderHpp(
            FixtureReportDataset::hppDraft(FixtureReportDataset::psychologist()),
        );

        $this->assertStringNotContainsString('SILP', $unsigned);
        $this->assertStringContainsString('Dewi Kartika, S.Psi.', $signed);
        $this->assertStringContainsString('SILP-00000000', $signed);
        $this->assertStringContainsString('STR-00000000', $signed);
    }

    public function test_hpp_rendering_is_deterministic(): void
    {
        $first = $this->renderer->renderHpp(FixtureReportDataset::hppDraft());
        $second = $this->renderer->renderHpp(FixtureReportDataset::hppDraft());

        $this->assertSame($first, $second);
    }
}
