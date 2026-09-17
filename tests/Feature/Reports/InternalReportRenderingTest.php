<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Services\ReportRendering\BladeReportRenderer;
use App\Services\ReportRendering\FixtureReportDataset;
use Tests\TestCase;

final class InternalReportRenderingTest extends TestCase
{
    private BladeReportRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = BladeReportRenderer::make();
    }

    public function test_internal_template_renders_identity_and_instrument_blocks(): void
    {
        $html = $this->renderer->renderInternal(FixtureReportDataset::internalDraft());

        $this->assertStringContainsString('Lembar Kerja Internal Psikolog', $html);
        $this->assertStringContainsString('Siti Rahmawati', $html);
        $this->assertStringContainsString('Blok Instrumen', $html);
        $this->assertStringContainsString('IST — Intelligenz Struktur Test', $html);
        $this->assertStringContainsString('Kraepelin — norma SMA/SMK', $html);
        $this->assertStringContainsString('RMIB — peringkat minat', $html);
        $this->assertStringContainsString('PAPI Kostick — 20 skala', $html);
        $this->assertStringContainsString('13,120', $html);
        $this->assertStringContainsString('5,032', $html);
    }

    public function test_internal_template_renders_validity_zones_integration_and_dass_detail(): void
    {
        $html = $this->renderer->renderInternal(FixtureReportDataset::internalDraft());

        $this->assertStringContainsString('Validitas Sesi', $html);
        $this->assertStringContainsString('Tempo Kraepelin manusiawi', $html);
        $this->assertStringContainsString('Ringkasan Zona', $html);
        $this->assertStringContainsString('INTEGRASI', $html);
        $this->assertStringContainsString('Gambaran Umum', $html);
        $this->assertStringContainsString('Saran Penempatan', $html);
        $this->assertStringContainsString('Hasil Rinci DASS-21', $html);
        $this->assertStringContainsString('Subskala', $html);
        $this->assertStringContainsString('Depresi', $html);
        $this->assertStringContainsString('Catatan Tinjauan', $html);
        $this->assertSame(7, substr_count($html, '<li value='));
    }

    public function test_internal_template_marks_aspect_codes_and_standards(): void
    {
        $html = $this->renderer->renderInternal(FixtureReportDataset::internalDraft());

        $this->assertStringContainsString('C2', $html);
        $this->assertStringContainsString('zone-grey', $html);
    }

    public function test_internal_rendering_is_deterministic_and_optional_psychologist(): void
    {
        $first = $this->renderer->renderInternal(FixtureReportDataset::internalDraft());
        $second = $this->renderer->renderInternal(FixtureReportDataset::internalDraft());
        $signed = $this->renderer->renderInternal(
            FixtureReportDataset::internalDraft(FixtureReportDataset::psychologist()),
        );

        $this->assertSame($first, $second);
        $this->assertStringNotContainsString('SIPP-00000000', $first);
        $this->assertStringContainsString('SIPP-00000000', $signed);
    }
}
