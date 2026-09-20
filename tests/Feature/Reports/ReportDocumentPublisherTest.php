<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Report\ReportIdentity;
use App\Services\ReportRendering\FixtureReportDataset;
use App\Services\ReportRendering\ReportDocumentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class ReportDocumentPublisherTest extends TestCase
{
    private ReportIdentity $identity;

    private ReportDocumentPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('reports');
        CarbonImmutable::setTestNow('2026-09-19 08:00:00');
        $this->identity = ReportIdentity::fromArray(
            FixtureReportDataset::hppDraft()->toViewData()['identity'],
        );
        $this->publisher = new ReportDocumentPublisher;
    }

    public function test_unknown_document_type_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown');

        $this->publisher->publish('summary', $this->identity, '%PDF-1.4 x');
    }

    public function test_non_pdf_binary_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PDF binary');

        $this->publisher->publish('hpp', $this->identity, 'plainly not a pdf');
    }

    public function test_publish_stores_privately_and_issues_a_short_lived_url(): void
    {
        $pdf = '%PDF-1.4 fixture-report-binary';

        $result = $this->publisher->publish('hpp', $this->identity, $pdf);

        $this->assertMatchesRegularExpression(
            '/^reports\/hpp\/[a-z0-9]{2}\/[a-z0-9]{62}\.pdf$/D',
            $result['object_key'],
        );
        $this->assertNotSame('', $result['url']);
        $this->assertSame(
            CarbonImmutable::now()->addMinutes(ReportDocumentPublisher::TEMPORARY_URL_MINUTES)->toIso8601String(),
            $result['expires_at'],
        );
        $this->assertSame(strlen($pdf), $result['size']);
        $this->assertSame(hash('sha256', $pdf), $result['checksum']);

        Storage::disk('reports')->assertExists($result['object_key']);
        Storage::disk('reports')->assertExists(
            $result['object_key'],
            $pdf,
        );
    }

    public function test_internal_document_type_uses_its_own_key_prefix(): void
    {
        $result = $this->publisher->publish('internal', $this->identity, '%PDF-1.4 internal');

        $this->assertStringStartsWith('reports/internal/', $result['object_key']);
        Storage::disk('reports')->assertExists($result['object_key']);
    }

    public function test_object_keys_are_random_not_deterministic(): void
    {
        $pdf = '%PDF-1.4 same-input-twice';

        $first = $this->publisher->publish('hpp', $this->identity, $pdf);
        $second = $this->publisher->publish('hpp', $this->identity, $pdf);

        $this->assertNotSame($first['object_key'], $second['object_key']);
        Storage::disk('reports')->assertExists($first['object_key']);
        Storage::disk('reports')->assertExists($second['object_key']);
    }

    public function test_object_key_leaks_nothing_from_the_report_identity(): void
    {
        $result = $this->publisher->publish('hpp', $this->identity, '%PDF-1.4 x');

        $values = $this->identity->toArray();

        foreach ([
            $values['participant_name'],
            $values['test_number'],
            $values['report_number'],
            str_replace('-', '', $values['report_number']),
        ] as $secret) {
            $this->assertStringNotContainsString(strtolower($secret), $result['object_key']);
        }
    }

    // ─── storeObject() / issueLink() split ───

    public function test_store_object_writes_a_new_object_and_never_signs_a_link(): void
    {
        $pdf = '%PDF-1.4 store-only';

        $stored = $this->publisher->storeObject('hpp', $pdf);

        $this->assertArrayNotHasKey('url', $stored);
        $this->assertArrayNotHasKey('expires_at', $stored);
        $this->assertSame(strlen($pdf), $stored['size']);
        $this->assertSame(hash('sha256', $pdf), $stored['checksum']);
        Storage::disk('reports')->assertExists($stored['object_key'], $pdf);
    }

    public function test_store_object_rejects_the_same_inputs_publish_rejects(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown');

        $this->publisher->storeObject('summary', '%PDF-1.4 x');
    }

    public function test_issue_link_signs_a_url_for_an_object_without_writing_anything(): void
    {
        $stored = $this->publisher->storeObject('hpp', '%PDF-1.4 issue-link-only');
        $before = Storage::disk('reports')->allFiles();

        $link = $this->publisher->issueLink($stored['object_key']);

        $this->assertNotSame('', $link['url']);
        $this->assertSame(
            CarbonImmutable::now()->addMinutes(ReportDocumentPublisher::TEMPORARY_URL_MINUTES)->toIso8601String(),
            $link['expires_at'],
        );
        // No new object appeared and the original one is untouched.
        $this->assertSame($before, Storage::disk('reports')->allFiles());
        Storage::disk('reports')->assertExists($stored['object_key']);
    }

    public function test_issue_link_can_be_called_repeatedly_for_the_same_key_without_storing_again(): void
    {
        $stored = $this->publisher->storeObject('hpp', '%PDF-1.4 repeat-link');

        $this->publisher->issueLink($stored['object_key']);
        $this->publisher->issueLink($stored['object_key']);

        $this->assertCount(1, Storage::disk('reports')->allFiles());
    }

    public function test_publish_composes_store_object_and_issue_link(): void
    {
        $pdf = '%PDF-1.4 composed';

        $result = $this->publisher->publish('hpp', $this->identity, $pdf);

        // Same object, same link contract as calling the two steps directly.
        $link = $this->publisher->issueLink($result['object_key']);
        $this->assertSame($result['url'], $link['url']);
        Storage::disk('reports')->assertExists($result['object_key'], $pdf);
    }
}
