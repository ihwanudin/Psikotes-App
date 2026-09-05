<?php

declare(strict_types=1);

// Standalone real Blade rendering: no application bootstrap, dotenv, HTTP or DB.
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
$cache = $root.'/storage/app/private/verification/summary-render-'.getmypid();
$files->ensureDirectoryExists($cache);
$compiler = new BladeCompiler($files, $cache);
$engines = new EngineResolver;
$engines->register('blade', fn () => new CompilerEngine($compiler, $files));
$container = new Container;
$views = new Factory($engines, new FileViewFinder($files, [$root.'/resources/views']), new Dispatcher($container));
$views->setContainer($container);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function fixture(): array
{
    $profile = [];
    foreach (['fullName' => 'Nama lengkap', 'birthDate' => 'Tanggal lahir', 'gender' => 'Jenis kelamin',
        'educationLevel' => 'Pendidikan terakhir', 'intendedField' => 'Bidang tujuan', 'email' => 'Email', 'phone' => 'Nomor telepon'] as $key => $label) {
        $profile[] = ['key' => $key, 'label' => $label, 'state' => 'missing', 'required' => $key !== 'email'];
    }

    return ['contractVersion' => 'checkout-summary-v2', 'sourceName' => 'Integrasi seleksi', 'branchName' => 'Cabang Sintetis',
        'packageName' => 'Paket Sintetis', 'packageSource' => 'catalog', 'attemptLabel' => 'Assessment Anda', 'profile' => $profile,
        'identityMessage' => 'Kelengkapan profil tidak menggantikan verifikasi identitas.',
        'payment' => ['payer' => 'unselected', 'state' => 'unselected', 'amountIdr' => null, 'amountSource' => 'unavailable', 'consultationRequested' => null, 'actionAvailable' => false, 'action' => null],
        'access' => ['state' => 'locked', 'tests' => [['testType' => 'dass21', 'state' => 'locked'], ['testType' => 'ist', 'state' => 'locked']], 'startAvailable' => false, 'message' => 'Akses tes belum siap.'],
        'consents' => ['psychotest' => ['state' => 'required', 'document' => ['version' => 'synthetic-v1', 'title' => 'Dokumen sintetis', 'text' => "Fixture saja.\nBukan teks legal."]], 'dass' => ['state' => 'required', 'document' => ['version' => 'synthetic-dass-v1', 'title' => 'DASS sintetis', 'text' => 'Fixture DASS.']], 'legalReviewPending' => true]];
}

function renderSummary(array $summary): array
{
    global $views;
    // Exact accepted controller encoding, with synthetic CSRF only.
    $json = json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $html = $views->make('checkout.summary', ['summary' => $summary, 'summaryJson' => $json, 'checkoutCsrf' => 'synthetic-csrf-only'])->render();
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $scripts = $xpath->query('//script');
    check($scripts->length === 1, 'Exactly one inert script');
    $script = $scripts->item(0);
    check($script->getAttribute('type') === 'application/json' && $script->getAttribute('id') === 'checkout-summary-v2', 'Inert script identity');
    check($script->textContent === $json && json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR) === $summary, 'Exact inert JSON unchanged');
    check(! str_contains($json, 'synthetic-csrf-only'), 'CSRF absent from summary');
    check(substr_count($html, 'synthetic-csrf-only') === 2, 'CSRF only meta and hidden input');
    check($xpath->query('//meta[@name="checkout-csrf-token" and @content="synthetic-csrf-only"]')->length === 1, 'CSRF meta');
    check($xpath->query('//form')->length === 1 && $xpath->query('//form[@method="post" and @action="/checkout/logout"]')->length === 1, 'Only existing logout form');
    check($xpath->query('//input')->length === 1 && $xpath->query('//form/input[@type="hidden" and @name="_checkout_csrf" and @value="synthetic-csrf-only"]')->length === 1, 'Only logout hidden field');
    check($xpath->query('//button')->length === 1 && trim($xpath->query('//button')->item(0)->textContent) === 'Keluar', 'No payment/start/consent button');
    check($xpath->query('//script[@src] | //style | //*[@style] | //select | //textarea')->length === 0, 'No executable script, inline style or editable fields');
    foreach ($xpath->query('//@*') as $attribute) {
        check(! str_starts_with(strtolower($attribute->nodeName), 'on'), 'No event handler attributes');
    }

    return [$xpath, $html];
}

function renderSummaryWithAction(array $summary): array
{
    global $views;
    $json = json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $csrf = 'ocsrf1_'.str_repeat('a', 64);
    $html = $views->make('checkout.summary', ['summary' => $summary, 'summaryJson' => $json, 'checkoutCsrf' => $csrf])->render();
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    check($xpath->query('//script[@type="application/json" and @id="checkout-summary-v2"]')->length === 1, 'V2 inert summary retained');
    check($xpath->query('//script')->length === 2, 'Only inert summary and payment enhancer');

    return [$xpath, $html];
}

function text(DOMXPath $xpath, string $query): string
{
    return trim($xpath->query($query)->item(0)?->textContent ?? '');
}

$cases = [];
foreach (['unselected' => 'Pembayar belum dipilih', 'unpaid' => 'Belum dibayar', 'unbilled' => 'Menunggu penagihan oleh lembaga',
    'preparing' => 'Pembayaran sedang disiapkan', 'pending' => 'Menunggu pembayaran', 'recovery_required' => 'Status pembayaran perlu diperiksa',
    'expired' => 'Pembayaran kedaluwarsa', 'rejected' => 'Pembayaran ditolak', 'paid' => 'Pembayaran lunas', 'free' => 'Gratis — tercatat oleh server'] as $state => $label) {
    $cases['payment '.$state] = function () use ($state, $label): void {
        $data = fixture();
        $data['payment']['state'] = $state;
        $data['payment']['payer'] = $state === 'unselected' ? 'unselected' : ($state === 'unbilled' ? 'organization' : 'self');
        if ($state === 'unbilled') {
            $data['payment']['organizationName'] = 'Cabang Sintetis';
        }
        if (! in_array($state, ['unselected', 'unbilled'], true)) {
            $data['packageSource'] = 'charge_snapshot';
            $data['payment']['amountSource'] = 'charge_snapshot';
            $data['payment']['amountIdr'] = $state === 'free' ? 0 : 175000;
            $data['payment']['consultationRequested'] = false;
        }
        [$xpath] = renderSummary($data);
        check(text($xpath, '//section[@aria-labelledby="payment-heading"]//dt[text()="Status"]/following-sibling::dd[1]') === $label, 'Localized payment state');
        check(str_contains(text($xpath, '//section[@aria-labelledby="access-heading"]'), 'Akses tes belum siap.'), 'Payment never opens access');
    };
}
$cases['amount null zero and safe integer'] = function (): void {
    foreach ([[null, 'Belum tersedia'], [0, 'Rp 0'], [175000, 'Rp 175.000'], [9007199254740991, 'Rp 9.007.199.254.740.991']] as [$amount, $expected]) {
        $data = fixture();
        $data['payment'] = ['payer' => 'self', 'state' => 'unpaid', 'amountIdr' => $amount, 'amountSource' => $amount === null ? 'unavailable' : 'charge_snapshot', 'consultationRequested' => $amount === null ? null : false, 'actionAvailable' => false, 'action' => null];
        $data['packageSource'] = $amount === null ? 'catalog' : 'charge_snapshot';
        [$xpath] = renderSummary($data);
        check(text($xpath, '//dt[text()="Nominal Anda"]/following-sibling::dd[1]') === $expected, 'Runtime IDR format');
    }
};
$cases['missing optional complete and whitespace'] = function (): void {
    $data = fixture();
    [$xpath] = renderSummary($data);
    check($xpath->query('//section[@aria-labelledby="profile-heading"]//dt')->length === 7, 'Seven fields retained');
    check(str_contains(text($xpath, '//section[@aria-labelledby="profile-heading"]'), 'Sebagian data wajib belum lengkap'), 'Required missing copy');
    foreach ($data['profile'] as $index => &$field) {
        if ($field['key'] !== 'email') {
            $field['state'] = 'locked';
            $field['displayValue'] = 'Nilai sintetis '.$index;
        }
    }
    unset($field);
    [$xpath] = renderSummary($data);
    check(str_contains(text($xpath, '//section[@aria-labelledby="profile-heading"]'), 'Data wajib sudah lengkap; email belum tersedia'), 'Optional email not required');
    $data['profile'][5] += ['displayValue' => 'synthetic@example.invalid'];
    $data['profile'][5]['state'] = 'locked';
    [$xpath] = renderSummary($data);
    check(str_contains(text($xpath, '//section[@aria-labelledby="profile-heading"]'), 'Data profil sudah tersedia'), 'Complete profile no registration');
    check(str_contains(text($xpath, '//section[@aria-labelledby="profile-heading"]'), 'Nilai sintetis 0'), 'Full values retained');
    $data['profile'][0]['displayValue'] = '   ';
    [$xpath] = renderSummary($data);
    check(text($xpath, '//section[@aria-labelledby="profile-heading"]//dd[1]') === 'Belum tersedia', 'Whitespace display fallback only');
};
$cases['access consent and provenance'] = function (): void {
    foreach (['locked' => 'Prasyarat tes belum terpenuhi', 'partial' => 'Sebagian prasyarat tes belum terpenuhi', 'ready' => 'Prasyarat tes terpenuhi'] as $state => $label) {
        $data = fixture();
        $data['access']['state'] = $state;
        $data['access']['tests'] = [['testType' => 'dass21', 'state' => 'locked'], ['testType' => 'ist', 'state' => 'ready']];
        $data['consents']['dass'] = ['state' => 'accepted', 'version' => 'synthetic-dass'];
        [$xpath] = renderSummary($data);
        check(str_contains(text($xpath, '//section[@aria-labelledby="access-heading"]'), $label), 'Localized access');
        check(str_contains(text($xpath, '//section[@aria-labelledby="access-heading"]'), 'DASS-21: Prasyarat belum terpenuhi'), 'Separate test access');
        check(str_contains(text($xpath, '//section[@aria-labelledby="consent-heading"]'), 'Persetujuan tercatat'), 'Accepted consent copy');
        check(str_contains(text($xpath, '//section[@aria-labelledby="consent-heading"]'), 'Persetujuan wajib untuk dokumen ini belum tercatat'), 'Required consent copy');
        check(text($xpath, '//dt[text()="Sumber informasi paket"]/following-sibling::dd[1]') === 'Informasi paket dari katalog', 'Catalog provenance label');
    }
};
$cases['hostile HTML allowed logo and assets'] = function (): void {
    $data = fixture();
    $hostile = '</script><img src=x onerror="alert(1)"> & \' 日本語';
    $data['branchName'] = $hostile;
    $data['profile'][0] = [...$data['profile'][0], 'state' => 'locked', 'displayValue' => $hostile];
    $data['consents']['psychotest']['document']['text'] = $hostile;
    [$xpath, $html] = renderSummary($data);
    check($xpath->query('//img')->length === 1, 'Only allowed logo, no injected image');
    check($xpath->query('//img[@src="/brand/oncam-logo-full-color.png" and @alt="ONCAM"]')->length === 1, 'Exact local logo');
    check($xpath->query('//link')->length === 1 && $xpath->query('//link[@rel="stylesheet" and @href="/css/checkout-summary-v1.css"]')->length === 1, 'Only local stylesheet');
    check(! str_contains($html, $hostile), 'Hostile markup escaped');
    check(text($xpath, '//dt[text()="Cabang"]/following-sibling::dd[1]') === $hostile, 'Escaping preserves actual text');
    check($xpath->query('//main')->length === 1 && $xpath->query('//h1')->length === 1, 'One main and heading');
};
$cases['server select capability renders unselected radios and server amounts only'] = function (): void {
    $data = fixture();
    $data['consents']['psychotest'] = ['state' => 'accepted', 'version' => 'synthetic-v1'];
    $data['consents']['dass'] = ['state' => 'accepted', 'version' => 'synthetic-dass-v1'];
    $data['consents']['legalReviewPending'] = false;
    $data['payment'] = ['payer' => 'self', 'state' => 'unpaid', 'amountIdr' => null, 'amountSource' => 'unavailable',
        'consultationRequested' => null, 'actionAvailable' => true, 'action' => ['path' => '/checkout/payment', 'mode' => 'select', 'currency' => 'IDR', 'choices' => [
            ['consultationRequested' => false, 'baseAmountIdr' => 99000, 'consultationAmountIdr' => 0, 'amountIdr' => 99000],
            ['consultationRequested' => true, 'baseAmountIdr' => 99000, 'consultationAmountIdr' => 50000, 'amountIdr' => 149000],
        ]]];
    [$xpath, $html] = renderSummaryWithAction($data);
    check($xpath->query('//form[@data-checkout-payment and @data-payment-mode="select" and @action="/checkout/payment"]')->length === 1, 'Fixed payment form');
    check($xpath->query('//form[@data-checkout-payment]//input[@type="radio" and @name="consultationRequested" and @required and @disabled and not(@checked)]')->length === 2, 'Two initially disabled unselected choices');
    check($xpath->query('//form[@data-checkout-payment]//button[@type="submit" and @disabled and @aria-disabled="true"]')->length === 1, 'Submit starts fail closed');
    check($xpath->query('//form[@data-checkout-payment]//*[@role="status" and @aria-live="polite"]')->length === 1, 'Accessible payment status');
    check($xpath->query('//script[@type="module" and @src="/js/checkout-payment-v1.js"]')->length === 1, 'Local enhancer only');
    check(str_contains(text($xpath, '//form[@data-checkout-payment]'), 'Rp 99.000') && str_contains(text($xpath, '//form[@data-checkout-payment]'), 'Rp 149.000'), 'Exact supplied totals displayed');
    check(! str_contains($html, 'amountIdr +') && ! str_contains($html, 'consultationAmountIdr +'), 'No browser amount expression');
};
$cases['continue capability is immutable and unavailable or positive organization has no controls'] = function (): void {
    $data = fixture();
    $data['payment'] = ['payer' => 'self', 'state' => 'pending', 'amountIdr' => 149000, 'amountSource' => 'charge_snapshot',
        'consultationRequested' => true, 'actionAvailable' => true, 'action' => ['path' => '/checkout/payment', 'mode' => 'continue', 'currency' => 'IDR', 'choices' => [
            ['consultationRequested' => true, 'baseAmountIdr' => 99000, 'consultationAmountIdr' => 50000, 'amountIdr' => 149000],
        ]]];
    $data['packageSource'] = 'charge_snapshot';
    [$continue] = renderSummaryWithAction($data);
    check($continue->query('//form[@data-checkout-payment and @data-payment-mode="continue"]//input[@type="hidden" and @name="consultationRequested" and @value="true" and @disabled]')->length === 1, 'Continue uses one immutable boolean');
    check($continue->query('//form[@data-checkout-payment]//input[@type="radio"]')->length === 0, 'Continue cannot change choice');

    $data['payment']['payer'] = 'organization';
    $data['payment']['organizationName'] = 'Cabang Sintetis';
    [$organization] = renderSummary($data);
    check($organization->query('//form[@data-checkout-payment] | //script[@src="/js/checkout-payment-v1.js"]')->length === 0, 'Positive organization capability is rejected');

    $data['payment']['payer'] = 'self';
    unset($data['payment']['organizationName']);
    $data['payment']['action']['path'] = '/checkout/other';
    [$malformed] = renderSummary($data);
    check($malformed->query('//form[@data-checkout-payment]')->length === 0, 'Malformed capability fails closed');
};
$cases['associative payment choices fail closed'] = function (): void {
    $data = fixture();
    $data['payment']['actionAvailable'] = true;
    $data['payment']['action'] = ['path' => '/checkout/payment', 'mode' => 'continue', 'currency' => 'IDR', 'choices' => [
        1 => ['consultationRequested' => false, 'baseAmountIdr' => 99000, 'consultationAmountIdr' => 0, 'amountIdr' => 99000],
    ]];
    [$xpath] = renderSummary($data);
    check($xpath->query('//form[@data-checkout-payment] | //script[@src="/js/checkout-payment-v1.js"]')->length === 0, 'Choices must be a JSON-style list');
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
echo count($cases).' cases, '.$failed." failures; real Blade only, no HTTP/browser/DB\n";
exit($failed === 0 ? 0 : 1);
