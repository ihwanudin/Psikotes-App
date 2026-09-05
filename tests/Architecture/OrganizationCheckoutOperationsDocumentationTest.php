<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class OrganizationCheckoutOperationsDocumentationTest extends TestCase
{
    private string $root;

    private string $document;

    private string $normalizedDocument;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = dirname(__DIR__, 2);
        $contents = file_get_contents($this->root.'/docs/ORGANIZATION_CHECKOUT_OPERATIONS.md');

        self::assertIsString($contents);
        $this->document = $contents;
        $normalized = preg_replace('/\s+/', ' ', $contents);
        self::assertIsString($normalized);
        $this->normalizedDocument = $normalized;
    }

    public function test_every_local_markdown_link_resolves_inside_the_repository(): void
    {
        $matches = [];
        $count = preg_match_all('/\[[^]]+]\((?<target>[^)]+)\)/', $this->document, $matches);

        self::assertIsInt($count);
        self::assertGreaterThan(0, $count);

        $documentDirectory = $this->root.'/docs';
        $canonicalRoot = realpath($this->root);
        self::assertIsString($canonicalRoot);

        foreach ($matches['target'] as $target) {
            self::assertIsString($target);

            $target = trim($target, "<> \t\n\r\0\x0B");
            $path = preg_split('/[?#]/', $target, 2)[0] ?? '';

            if ($path === '' || str_starts_with($target, '#') || parse_url($target, PHP_URL_SCHEME) !== null) {
                continue;
            }

            $resolved = realpath($documentDirectory.'/'.rawurldecode($path));

            self::assertIsString($resolved, "Runbook link does not resolve: {$target}");
            self::assertTrue(
                $resolved === $canonicalRoot || str_starts_with($resolved, $canonicalRoot.DIRECTORY_SEPARATOR),
                "Runbook link escapes the repository: {$target}",
            );
        }
    }

    public function test_claimed_payment_actions_and_coordinator_exist_at_the_documented_boundary(): void
    {
        $classes = [
            'ClaimAssessmentBillInvoice',
            'IssueAssessmentBillInvoice',
            'PersistAssessmentInvoiceOutcome',
            'CoordinateAssessmentInvoiceReconciliation',
        ];

        foreach ($classes as $class) {
            self::assertStringContainsString($class, $this->document);

            $path = $this->root."/app/Actions/Payments/{$class}.php";
            self::assertFileExists($path, "Documented payment class is missing: {$class}");

            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertMatchesRegularExpression(
                '/\b(?:final\s+readonly\s+|final\s+|readonly\s+)?class\s+'.preg_quote($class, '/').'\b/',
                $source,
                "Documented payment class declaration is missing: {$class}",
            );
        }
    }

    public function test_runbook_remains_provisional_and_fail_closed(): void
    {
        self::assertStringContainsString('**Status: DRAFT / PROVISIONAL (P18).**', $this->document);
        self::assertStringContainsString('Dokumen ini belum menutup acceptance', $this->normalizedDocument);
        self::assertStringContainsString('Seluruh switch checkout', $this->normalizedDocument);
        self::assertStringContainsString('writer tetap **OFF**', $this->normalizedDocument);
        self::assertStringContainsString('Tidak ada aktivasi global implisit.', $this->normalizedDocument);
        self::assertStringContainsString('hanya untuk sumber yang disetujui secara eksplisit', $this->normalizedDocument);
        self::assertStringContainsString('tidak boleh jatuh kembali ke provisioning v1', $this->normalizedDocument);
        self::assertStringContainsString('bukan memasuki endpoint v1', $this->normalizedDocument);
        self::assertStringContainsString('jangan mencentang acceptance P18', $this->normalizedDocument);
    }

    public function test_runbook_forbids_automatic_release_or_reinvoice_and_preserves_history_on_rollback(): void
    {
        self::assertStringContainsString('tidak melakukan auto-release maupun auto-reinvoice', $this->normalizedDocument);
        self::assertStringContainsString('Jangan auto-release, auto-reinvoice', $this->normalizedDocument);
        self::assertStringContainsString(
            'Rollback yang aman adalah rollback **aplikasi/traffic**, bukan penghapusan state',
            $this->normalizedDocument,
        );
        self::assertStringContainsString(
            'Simpan marker `checkout-v2`, bill, item, charge, intent, lease, audit, outbox, consent, dan histori.',
            $this->normalizedDocument,
        );
        self::assertStringContainsString('jangan rollback migration/policy privacy audit consent', $this->normalizedDocument);
        self::assertStringContainsString(
            'Redact data peserta, signature, token, URL bukti/invoice',
            $this->normalizedDocument,
        );
    }

    public function test_reconciliation_command_job_and_scheduler_are_explicitly_not_wired(): void
    {
        self::assertStringContainsString(
            'Kode secara eksplisit belum mendaftarkan command, job, route, atau scheduler.',
            $this->normalizedDocument,
        );
        self::assertStringContainsString(
            'tidak ada command/job/scheduler yang diasumsikan aktif tanpa wiring dan review',
            $this->normalizedDocument,
        );
        self::assertStringContainsString(
            '**jangan** memakai command `payments:reconcile-xendit` sebagai pengganti',
            $this->normalizedDocument,
        );
    }

    public function test_bill_state_matrix_is_exact_and_uses_canonical_invoice_audit_actions(): void
    {
        $rows = $this->markdownTableBetweenHeadings(
            'Triage berdasarkan state bill',
            'Claim dan penerbitan invoice',
            ['State', 'Arti operasional', 'Tindakan aman', 'Larangan'],
        );

        self::assertCount(7, $rows);
        foreach ($rows as $row) {
            self::assertCount(4, $row);
        }
        self::assertSame(
            ['`reserved`', '`issuing`', '`unknown`', '`pending`', '`expired`', '`rejected`', '`paid`'],
            array_column($rows, 0),
        );

        $source = file_get_contents($this->root.'/app/Actions/Payments/PersistAssessmentInvoiceOutcome.php');
        self::assertIsString($source);

        foreach (['assessment_bill.invoice_unknown', 'assessment_bill.invoice_issued'] as $action) {
            self::assertStringContainsString("`{$action}`", $this->document);
            self::assertStringContainsString("'{$action}'", $source);
        }

        self::assertStringNotContainsString('`invoice_unknown`', $this->document);
        self::assertStringNotContainsString('`invoice_issued`', $this->document);
    }

    public function test_documented_activation_gate_groups_match_default_off_config(): void
    {
        $configuration = require $this->root.'/config/assessment_integration.php';
        self::assertIsArray($configuration);

        $expectedGroups = [
            'checkout utama' => ['assessment_integration.checkout.enabled'],
            'handoff' => ['assessment_integration.checkout_handoff.enabled'],
            'sesi' => ['assessment_integration.checkout_session.enabled'],
            'konfirmasi' => [
                'assessment_integration.checkout_session.http.confirmation.enabled',
                'assessment_integration.checkout_session.http.confirmation.writer_enabled',
            ],
            'pembayaran' => [
                'assessment_integration.checkout_session.http.payment.enabled',
                'assessment_integration.checkout_session.http.payment.writer_enabled',
            ],
        ];

        $rows = $this->markdownTableBetweenHeadings(
            'Urutan aktivasi yang aman',
            'Rollback dan containment',
            ['Kelompok gate', 'Key konfigurasi exact', 'Dependensi sebelum aktivasi'],
        );
        self::assertCount(5, $rows);

        $documentedGroups = [];
        $allKeysInTable = [];
        foreach ($rows as $row) {
            self::assertCount(3, $row);

            $matches = [];
            $count = preg_match_all('/`(?<key>assessment_integration\.[a-z_.]+)`/', implode(' | ', $row), $matches);
            self::assertIsInt($count);
            $allKeysInTable = [...$allKeysInTable, ...$matches['key']];

            $keyCellMatches = [];
            $keyCellCount = preg_match_all('/`(?<key>assessment_integration\.[a-z_.]+)`/', $row[1], $keyCellMatches);
            self::assertIsInt($keyCellCount);
            self::assertGreaterThan(0, $keyCellCount, "Activation gate group has no key: {$row[0]}");
            self::assertSame($matches['key'], $keyCellMatches['key'], "Activation keys moved outside the key cell: {$row[0]}");

            $documentedGroups[$row[0]] = $keyCellMatches['key'];
        }

        self::assertSame($expectedGroups, $documentedGroups);

        $expectedKeys = array_merge(...array_values($expectedGroups));
        self::assertCount(7, $allKeysInTable);
        self::assertCount(7, array_unique($allKeysInTable));
        self::assertSame($expectedKeys, $allKeysInTable);

        foreach ($documentedGroups as $keys) {
            foreach ($keys as $key) {
                $segments = explode('.', preg_replace('/^assessment_integration\./', '', $key) ?? $key);
                $value = $configuration;
                foreach ($segments as $segment) {
                    self::assertIsArray($value);
                    self::assertArrayHasKey($segment, $value, "Missing configuration key: {$key}");
                    $value = $value[$segment];
                }
                self::assertFalse($value, "Activation gate must remain OFF by default: {$key}");
            }
        }

        self::assertStringContainsString(
            'Kelima kelompok gate tetap berbeda dan tidak boleh diperlakukan sebagai satu switch.',
            $this->normalizedDocument,
        );
    }

    /**
     * @param  list<string>  $expectedHeader
     * @return list<list<string>>
     */
    private function markdownTableBetweenHeadings(
        string $heading,
        string $nextHeading,
        array $expectedHeader,
    ): array {
        $matches = [];
        $count = preg_match(
            '/^## '.preg_quote($heading, '/').'\R(?<section>.*?)^## '.preg_quote($nextHeading, '/').'\R/ms',
            $this->document,
            $matches,
        );

        self::assertSame(1, $count, "Unable to isolate documentation section: {$heading}");
        self::assertArrayHasKey('section', $matches);

        $lines = preg_split('/\R/', $matches['section']);
        self::assertIsArray($lines);

        $headerLine = '| '.implode(' | ', $expectedHeader).' |';
        $headerIndexes = array_keys($lines, $headerLine, true);
        self::assertCount(1, $headerIndexes, "Expected exactly one table header in section: {$heading}");

        $headerIndex = $headerIndexes[0];
        self::assertSame(
            '| '.implode(' | ', array_fill(0, count($expectedHeader), '---')).' |',
            $lines[$headerIndex + 1] ?? null,
            "Malformed table separator in section: {$heading}",
        );

        $rows = [];
        for ($index = $headerIndex + 2; isset($lines[$index]); $index++) {
            $line = $lines[$index];
            if (! str_starts_with($line, '| ') || ! str_ends_with($line, ' |')) {
                break;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));
            self::assertCount(count($expectedHeader), $cells, "Malformed table row in section: {$heading}");
            $rows[] = $cells;
        }

        return $rows;
    }
}
