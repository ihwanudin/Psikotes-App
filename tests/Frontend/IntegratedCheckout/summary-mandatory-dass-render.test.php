<?php

declare(strict_types=1);

// Standalone real Blade rendering with synthetic props; no app bootstrap, HTTP or DB.
require dirname(__DIR__, 3).'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

$root = dirname(__DIR__, 3);
$files = new Filesystem;
$cache = $root.'/storage/app/private/verification/summary-mandatory-dass-'.getmypid();
$files->ensureDirectoryExists($cache);
$compiler = new BladeCompiler($files, $cache);
$engines = new EngineResolver;
$engines->register('blade', fn () => new CompilerEngine($compiler, $files));
$views = new Factory($engines, new FileViewFinder($files, [$root.'/resources/views']), new Dispatcher(new Container));

function mandatoryDassCheck(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function mandatoryDassFixture(): array
{
    $profile = [];
    foreach (['fullName' => 'Nama lengkap', 'birthDate' => 'Tanggal lahir', 'gender' => 'Jenis kelamin',
        'educationLevel' => 'Pendidikan terakhir', 'intendedField' => 'Bidang tujuan', 'email' => 'Email', 'phone' => 'Nomor telepon'] as $key => $label) {
        $profile[] = ['key' => $key, 'label' => $label, 'state' => 'missing', 'required' => $key !== 'email'];
    }

    return ['contractVersion' => 'checkout-summary-v1', 'sourceName' => 'Integrasi seleksi', 'branchName' => 'Cabang Sintetis',
        'packageName' => 'Paket Sintetis + DASS-21', 'packageSource' => 'charge_snapshot', 'attemptLabel' => 'Assessment Anda',
        'profile' => $profile, 'identityMessage' => 'Kelengkapan profil tidak menggantikan verifikasi identitas.',
        'payment' => ['payer' => 'self', 'state' => 'paid', 'amountIdr' => 175000, 'amountSource' => 'charge_snapshot',
            'consultationRequested' => false, 'actionAvailable' => false],
        'access' => ['state' => 'locked', 'tests' => [['testType' => 'ist', 'state' => 'ready'], ['testType' => 'dass21', 'state' => 'locked']],
            'startAvailable' => false, 'message' => 'Profil dan persetujuan belum lengkap.'],
        'consents' => [
            'psychotest' => ['state' => 'accepted', 'version' => 'synthetic-psychotest-v1'],
            'dass' => ['state' => 'required', 'document' => ['version' => 'synthetic-dass-v1',
                'title' => 'Persetujuan skrining DASS-21 sebagai bagian psikotes', 'text' => 'Fixture sintetis, bukan teks legal.']],
            'legalReviewPending' => false,
        ]];
}

/** @return array{DOMXPath, string} */
function renderMandatoryDass(array $summary): array
{
    global $views;
    $json = json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $html = $views->make('checkout.summary', ['summary' => $summary, 'summaryJson' => $json,
        'checkoutCsrf' => 'synthetic-csrf-only'])->render();
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return [new DOMXPath($dom), $html];
}

function mandatoryDassText(DOMXPath $xpath, string $query): string
{
    return trim($xpath->query($query)->item(0)?->textContent ?? '');
}

$cases = [];
$cases['missing required profile and required DASS are explicit'] = function (): void {
    [$xpath] = renderMandatoryDass(mandatoryDassFixture());
    $steps = mandatoryDassText($xpath, '//section[@aria-labelledby="requirements-heading"]');
    mandatoryDassCheck(str_contains($steps, '6 data profil wajib belum lengkap'), 'Required count is server-projected profile state');
    mandatoryDassCheck(str_contains($steps, 'Nama lengkap') && str_contains($steps, 'Nomor telepon'), 'Missing labels remain visible');
    mandatoryDassCheck(! str_contains($steps, 'Email'), 'Optional email is not called required');
    mandatoryDassCheck(str_contains($steps, 'Persetujuan DASS-21 wajib belum tercatat'), 'DASS requirement is explicit');
    $consent = mandatoryDassText($xpath, '//section[@aria-labelledby="consent-heading"]');
    mandatoryDassCheck(str_contains($consent, 'DASS-21 (wajib untuk paket ini)'), 'DASS is not labelled optional');
    mandatoryDassCheck(str_contains($consent, 'Hasil DASS-21 tidak memengaruhi kelayakan'), 'Eligibility separation is explicit');
    mandatoryDassCheck($xpath->query('//input[@type="checkbox" or @type="radio"]')->length === 0, 'Readonly summary offers no yes/no choice');
    mandatoryDassCheck($xpath->query('//form')->length === 1 && $xpath->query('//button')->length === 1, 'Logout remains the only action');
};
$cases['accepted DASS and complete profile do not request data again'] = function (): void {
    $summary = mandatoryDassFixture();
    foreach ($summary['profile'] as $index => &$field) {
        $field['state'] = 'locked';
        $field['displayValue'] = $field['key'] === 'email' ? 'synthetic@example.invalid' : 'Nilai lengkap '.$index;
    }
    unset($field);
    $summary['consents']['dass'] = ['state' => 'accepted', 'version' => 'synthetic-dass-v1'];
    [$xpath] = renderMandatoryDass($summary);
    mandatoryDassCheck($xpath->query('//section[@aria-labelledby="requirements-heading"]')->length === 0, 'No duplicate completion prompt');
    mandatoryDassCheck(str_contains(mandatoryDassText($xpath, '//section[@aria-labelledby="profile-heading"]'), 'Nilai lengkap 0'), 'Locked values retained');
    mandatoryDassCheck(str_contains(mandatoryDassText($xpath, '//section[@aria-labelledby="consent-heading"]'), 'Persetujuan tercatat'), 'Accepted server state retained');
};
$cases['not applicable remains fail closed without mandatory claim'] = function (): void {
    $summary = mandatoryDassFixture();
    $summary['consents']['dass'] = ['state' => 'not_applicable'];
    [$xpath] = renderMandatoryDass($summary);
    $consent = mandatoryDassText($xpath, '//section[@aria-labelledby="consent-heading"]');
    mandatoryDassCheck(str_contains($consent, 'Tidak berlaku untuk paket ini'), 'Existing state retained');
    mandatoryDassCheck(! str_contains($consent, 'wajib untuk paket ini'), 'No client-invented package authority');
};
$cases['DASS consent presentation never changes server access or payment'] = function (): void {
    $summary = mandatoryDassFixture();
    $summary['access']['state'] = 'ready';
    $summary['access']['message'] = 'Status akses sintetis dari server.';
    [$xpath] = renderMandatoryDass($summary);
    mandatoryDassCheck(str_contains(mandatoryDassText($xpath, '//section[@aria-labelledby="access-heading"]'), 'Prasyarat tes terpenuhi'), 'Access remains server-projected');
    mandatoryDassCheck(str_contains(mandatoryDassText($xpath, '//section[@aria-labelledby="payment-heading"]'), 'Rp 175.000'), 'Readonly amount retained');
    mandatoryDassCheck(str_contains(mandatoryDassText($xpath, '//section[@aria-labelledby="access-heading"]'), 'Mulai tes belum tersedia'), 'Presentation does not open access');
};

$failed = 0;
foreach ($cases as $name => $case) {
    try {
        $case();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL {$name}: {$error->getMessage()}\n";
    }
}
echo count($cases).' cases, '.$failed." failures; real Blade, synthetic props, no HTTP/browser/DB\n";
exit($failed === 0 ? 0 : 1);
