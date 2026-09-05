@php
    $paymentLabels = [
        'unselected' => 'Pembayar belum dipilih', 'unpaid' => 'Belum dibayar',
        'unbilled' => 'Menunggu penagihan oleh lembaga', 'preparing' => 'Pembayaran sedang disiapkan',
        'pending' => 'Menunggu pembayaran', 'recovery_required' => 'Status pembayaran perlu diperiksa',
        'expired' => 'Pembayaran kedaluwarsa', 'rejected' => 'Pembayaran ditolak',
        'paid' => 'Pembayaran lunas', 'free' => 'Gratis — tercatat oleh server',
    ];
    $payerLabels = ['unselected' => 'Pembayar belum dipilih', 'self' => 'Bayar sendiri', 'organization' => 'Dibayar lembaga'];
    $accessLabels = ['locked' => 'Prasyarat tes belum terpenuhi', 'partial' => 'Sebagian prasyarat tes belum terpenuhi', 'ready' => 'Prasyarat tes terpenuhi'];
    $testLabels = ['ist' => 'IST', 'papi' => 'PAPI', 'rmib' => 'RMIB', 'kraepelin' => 'Kraepelin', 'dass21' => 'DASS-21'];
    $missingRequiredProfile = array_values(array_filter($summary['profile'], fn ($field) => $field['state'] === 'missing' && $field['required']));
    $requiredMissing = $missingRequiredProfile !== [];
    $optionalMissing = count(array_filter($summary['profile'], fn ($field) => $field['state'] === 'missing' && ! $field['required'])) > 0;
    $psychotestConsentRequired = $summary['consents']['psychotest']['state'] === 'required';
    $dassConsentRequired = $summary['consents']['dass']['state'] === 'required';
    $requiredConsentTypes = array_values(array_filter(['psychotest', 'dass'], fn ($type) =>
        $summary['consents'][$type]['state'] === 'required'));
    $confirmation = is_array($confirmationForm ?? null) ? $confirmationForm : null;
    $profileByKey = [];
    foreach ($summary['profile'] as $field) {
        $profileByKey[$field['key']] = $field;
    }
    $missingProfileKeys = array_keys($profileByKey);
    $missingProfileKeys = array_values(array_filter($missingProfileKeys, fn ($key) =>
        $profileByKey[$key]['state'] === 'missing' && $profileByKey[$key]['required'] && $key !== 'email'));
    sort($missingProfileKeys);
    $confirmationProfileKeys = is_array($confirmation['profile'] ?? null) ? array_keys($confirmation['profile']) : [];
    sort($confirmationProfileKeys);
    $confirmationConsentTypes = is_array($confirmation['consents'] ?? null) ? array_keys($confirmation['consents']) : [];
    $confirmationReady = is_array($confirmation)
        && array_keys($confirmation) === ['action', 'profile', 'consents']
        && ($confirmation['action'] ?? null) === '/checkout/confirm'
        && $confirmationProfileKeys === $missingProfileKeys
        && $confirmationConsentTypes === $requiredConsentTypes
        && ($missingProfileKeys !== [] || $requiredConsentTypes !== [])
        && ! $summary['consents']['legalReviewPending'];
    foreach ($requiredConsentTypes as $type) {
        $consent = $confirmation['consents'][$type] ?? null;
        $consentKeys = is_array($consent) ? array_keys($consent) : [];
        sort($consentKeys);
        $confirmationReady = $confirmationReady && is_array($consent)
            && $consentKeys === ['documentHash', 'documentVersion']
            && is_string($consent['documentVersion'])
            && hash_equals($summary['consents'][$type]['document']['version'], $consent['documentVersion'])
            && is_string($consent['documentHash'])
            && preg_match('/^[0-9a-f]{64}$/D', $consent['documentHash']) === 1;
    }
    foreach ($missingProfileKeys as $key) {
        $descriptor = $confirmation['profile'][$key] ?? null;
        $control = is_array($descriptor) ? ($descriptor['control'] ?? null) : null;
        $confirmationReady = $confirmationReady && in_array($control, ['text', 'date', 'tel', 'select'], true);
        if ($control === 'select') {
            $options = $descriptor['options'] ?? null;
            $confirmationReady = $confirmationReady && is_array($options) && $options !== [];
            foreach (is_array($options) ? $options : [] as $option) {
                $confirmationReady = $confirmationReady && is_array($option)
                    && array_keys($option) === ['value', 'label']
                    && is_string($option['value']) && trim($option['value']) !== ''
                    && is_string($option['label']) && trim($option['label']) !== '';
            }
        }
    }
    $exactKeys = static function (array $value, array $expected): bool {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    };
    $safeAmount = static fn ($value): bool => is_int($value) && $value >= 0 && $value <= 9007199254740991;
    $paymentAction = $summary['payment']['action'] ?? null;
    $paymentChoices = is_array($paymentAction['choices'] ?? null) ? $paymentAction['choices'] : [];
    $paymentActionReady = ($summary['contractVersion'] ?? null) === 'checkout-summary-v2'
        && ($summary['payment']['actionAvailable'] ?? false) === true
        && ((($summary['packageSource'] ?? null) === 'catalog'
            && ($summary['payment']['amountSource'] ?? null) === 'unavailable')
            || (($summary['packageSource'] ?? null) === 'charge_snapshot'
                && ($summary['payment']['amountSource'] ?? null) === 'charge_snapshot'))
        && is_array($paymentAction)
        && $exactKeys($paymentAction, ['path', 'mode', 'currency', 'choices'])
        && $paymentAction['path'] === '/checkout/payment'
        && in_array($paymentAction['mode'], ['select', 'continue'], true)
        && $paymentAction['currency'] === 'IDR'
        && array_is_list($paymentChoices)
        && count($paymentChoices) >= 1 && count($paymentChoices) <= 2
        && ($paymentAction['mode'] !== 'continue' || count($paymentChoices) === 1);
    $previousConsultation = null;
    foreach ($paymentChoices as $choice) {
        $choiceValid = is_array($choice)
            && $exactKeys($choice, ['consultationRequested', 'baseAmountIdr', 'consultationAmountIdr', 'amountIdr'])
            && is_bool($choice['consultationRequested'] ?? null)
            && $safeAmount($choice['baseAmountIdr'] ?? null)
            && $safeAmount($choice['consultationAmountIdr'] ?? null)
            && $safeAmount($choice['amountIdr'] ?? null)
            && $choice['baseAmountIdr'] <= 9007199254740991 - $choice['consultationAmountIdr']
            && $choice['amountIdr'] === $choice['baseAmountIdr'] + $choice['consultationAmountIdr']
            && ($choice['consultationRequested']
                ? $choice['consultationAmountIdr'] > 0
                : $choice['consultationAmountIdr'] === 0)
            && $choice['consultationRequested'] !== $previousConsultation;
        if ($previousConsultation === true && ($choice['consultationRequested'] ?? null) === false) {
            $choiceValid = false;
        }
        $paymentActionReady = $paymentActionReady && $choiceValid;
        $previousConsultation = $choice['consultationRequested'] ?? null;
    }
    if (($summary['payment']['payer'] ?? null) === 'organization' && $paymentActionReady) {
        $organizationChoice = count($paymentChoices) === 1 ? $paymentChoices[0] : null;
        $paymentActionReady = is_array($organizationChoice)
            && $paymentAction['mode'] === 'select'
            && $organizationChoice['consultationRequested'] === false
            && $organizationChoice['baseAmountIdr'] === 0
            && $organizationChoice['consultationAmountIdr'] === 0
            && $organizationChoice['amountIdr'] === 0;
    }
    if ($paymentActionReady) {
        $payment = $summary['payment'];
        if (($payment['payer'] ?? null) === 'self' && $paymentAction['mode'] === 'select') {
            $paymentActionReady = ($payment['state'] ?? null) === 'unpaid'
                && ($payment['amountSource'] ?? null) === 'unavailable'
                && ($payment['amountIdr'] ?? null) === null
                && ($payment['consultationRequested'] ?? null) === null;
        } elseif (($payment['payer'] ?? null) === 'self' && $paymentAction['mode'] === 'continue') {
            $continueChoice = $paymentChoices[0] ?? null;
            $paymentActionReady = ($payment['state'] ?? null) === 'pending'
                && ($payment['amountSource'] ?? null) === 'charge_snapshot'
                && is_array($continueChoice)
                && ($payment['amountIdr'] ?? null) === $continueChoice['amountIdr']
                && ($payment['consultationRequested'] ?? null) === $continueChoice['consultationRequested'];
        } elseif (($payment['payer'] ?? null) === 'organization') {
            $paymentActionReady = ($payment['state'] ?? null) === 'unbilled'
                && ($payment['amountSource'] ?? null) === 'unavailable'
                && ($payment['amountIdr'] ?? null) === null
                && ($payment['consultationRequested'] ?? null) === null;
        } else {
            $paymentActionReady = false;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="checkout-csrf-token" content="{{ $checkoutCsrf }}">
    <title>Ringkasan assessment</title>
    <link rel="stylesheet" href="/css/checkout-summary-v1.css">
</head>
<body>
<header class="brand-header">
    <img src="/brand/oncam-logo-full-color.png" alt="ONCAM" width="96" height="80">
    <p>Ringkasan pribadi peserta</p>
</header>
<main>
    <h1>Ringkasan assessment</h1>
    <p class="intro">Halaman ini menampilkan ringkasan pribadi Anda. Pembayaran dan mulai tes tetap mengikuti status dari server.</p>
    <section class="assessment" aria-labelledby="assessment-heading">
        <h2 id="assessment-heading">{{ $summary['attemptLabel'] }}</h2>
        <dl>
            <dt>Sumber</dt><dd>{{ $summary['sourceName'] }}</dd>
            <dt>Cabang</dt><dd>{{ $summary['branchName'] }}</dd>
            <dt>Paket</dt><dd>{{ $summary['packageName'] }}</dd>
            <dt>Sumber informasi paket</dt><dd>{{ $summary['packageSource'] === 'catalog' ? 'Informasi paket dari katalog' : 'Informasi paket saat biaya ditetapkan' }}</dd>
        </dl>
    </section>
    @if ($requiredMissing || $psychotestConsentRequired || $dassConsentRequired)
        <section class="requirements" aria-labelledby="requirements-heading">
            <h2 id="requirements-heading">Yang perlu dilengkapi</h2>
            <p>Status ini berasal dari data checkout Anda. {{ $confirmationReady ? 'Lengkapi hanya data dan persetujuan yang masih diperlukan di bawah.' : 'Pengisian dan persetujuan belum tersedia di halaman ringkasan ini.' }}</p>
            <ul class="requirements-list">
                @if ($requiredMissing)
                    <li>
                        <strong>{{ count($missingRequiredProfile) }} data profil wajib belum lengkap.</strong>
                        <span>{{ implode(', ', array_column($missingRequiredProfile, 'label')) }}.</span>
                    </li>
                @endif
                @if ($psychotestConsentRequired)
                    <li><strong>Persetujuan psikotes wajib belum tercatat.</strong></li>
                @endif
                @if ($dassConsentRequired)
                    <li>
                        <strong>Persetujuan DASS-21 wajib belum tercatat.</strong>
                        <span>DASS-21 merupakan bagian wajib paket, tetapi hasilnya tidak memengaruhi kelayakan.</span>
                    </li>
                @endif
            </ul>
        </section>
    @endif
    <section aria-labelledby="profile-heading">
        <h2 id="profile-heading">Profil Anda</h2>
        @if ($requiredMissing)
            <p>Sebagian data wajib belum lengkap{{ $confirmationReady ? '; hanya data tersebut yang dapat diisi pada formulir konfirmasi.' : '; pengisian belum tersedia di halaman ini.' }}</p>
        @elseif ($optionalMissing)
            <p>Data wajib sudah lengkap; email belum tersedia.</p>
        @else
            <p>Data profil sudah tersedia.</p>
        @endif
        <dl>
            @foreach ($summary['profile'] as $field)
                <dt>{{ $field['label'] }}{{ $field['required'] ? ' (wajib)' : ' (opsional)' }}</dt>
                <dd>{{ $field['state'] === 'missing' ? ($field['required'] ? 'Belum dilengkapi' : 'Belum tersedia (opsional)') : (trim($field['displayValue']) === '' ? 'Belum tersedia' : $field['displayValue']) }}</dd>
            @endforeach
        </dl>
        <p class="notice">{{ $summary['identityMessage'] }}</p>
    </section>
    <section aria-labelledby="payment-heading">
        <h2 id="payment-heading">Pembayaran</h2>
        <dl>
            <dt>Pembayar</dt><dd>{{ $payerLabels[$summary['payment']['payer']] }}</dd>
            @if (isset($summary['payment']['organizationName']))
                <dt>Organisasi</dt><dd>{{ $summary['payment']['organizationName'] }}</dd>
            @endif
            <dt>Status</dt><dd class="payment-status">{{ $paymentLabels[$summary['payment']['state']] }}</dd>
            <dt>Nominal Anda</dt><dd class="amount">{{ $summary['payment']['amountIdr'] === null ? 'Belum tersedia' : 'Rp '.number_format($summary['payment']['amountIdr'], 0, ',', '.') }}</dd>
            <dt>Sumber nominal</dt><dd>{{ $summary['payment']['amountSource'] === 'unavailable' ? 'Nominal belum tersedia' : 'Nominal biaya yang tercatat' }}</dd>
            <dt>Konsultasi diminta</dt><dd>{{ $summary['payment']['consultationRequested'] === null ? 'Belum tersedia' : ($summary['payment']['consultationRequested'] ? 'Ya' : 'Tidak') }}</dd>
        </dl>
    </section>
    @if ($paymentActionReady)
        <form class="payment-form" data-checkout-payment data-payment-mode="{{ $paymentAction['mode'] }}" method="post" action="/checkout/payment">
            <h2>Langkah pembayaran</h2>
            @if ($paymentAction['mode'] === 'select')
                <fieldset>
                    <legend>Pilih layanan pembayaran</legend>
                    <p>Pilih salah satu nominal yang telah ditetapkan server.</p>
                    <div class="payment-choices">
                        @foreach ($paymentChoices as $index => $choice)
                            <label class="payment-choice" for="checkout-payment-choice-{{ $index }}">
                                <input id="checkout-payment-choice-{{ $index }}" type="radio" name="consultationRequested"
                                    value="{{ $choice['consultationRequested'] ? 'true' : 'false' }}" required disabled>
                                <span>
                                    <strong>{{ $choice['consultationRequested'] ? 'Tes dengan konsultasi psikolog' : 'Tes tanpa konsultasi psikolog' }}</strong>
                                    <span>Total: Rp {{ number_format($choice['amountIdr'], 0, ',', '.') }}</span>
                                    <small>Biaya tes: Rp {{ number_format($choice['baseAmountIdr'], 0, ',', '.') }}@if ($choice['consultationRequested']) · Konsultasi: Rp {{ number_format($choice['consultationAmountIdr'], 0, ',', '.') }}@endif</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @else
                @php($choice = $paymentChoices[0])
                <input type="hidden" name="consultationRequested" value="{{ $choice['consultationRequested'] ? 'true' : 'false' }}" disabled>
                <div class="payment-choice payment-choice-fixed" aria-label="Pilihan pembayaran yang sudah tersimpan">
                    <span>
                        <strong>{{ $choice['consultationRequested'] ? 'Tes dengan konsultasi psikolog' : 'Tes tanpa konsultasi psikolog' }}</strong>
                        <span>Total tersimpan: Rp {{ number_format($choice['amountIdr'], 0, ',', '.') }}</span>
                        <small>Pilihan ini sudah tersimpan dan tidak dapat diubah di halaman ini.</small>
                    </span>
                </div>
            @endif
            <p class="transport-status" data-payment-status role="status" aria-live="polite" tabindex="-1">Kontrol pembayaran sedang divalidasi.</p>
            <noscript><p class="transport-warning">JavaScript diperlukan untuk melanjutkan pembayaran. Ringkasan dan nominal dari server tetap dapat dibaca.</p></noscript>
            <button type="submit" disabled aria-disabled="true">{{ $paymentAction['mode'] === 'continue' ? 'Lanjutkan pembayaran' : 'Buat pembayaran' }}</button>
        </form>
        <script type="module" src="/js/checkout-payment-v1.js"></script>
    @endif
    <section class="access" aria-labelledby="access-heading">
        <h2 id="access-heading">Akses tes</h2>
        <p>{{ $summary['access']['message'] }}</p>
        <p>Status: {{ $accessLabels[$summary['access']['state']] }}</p>
        <p>Mulai tes belum tersedia di halaman ini.</p>
        <ul>
            @foreach ($summary['access']['tests'] as $test)
                <li>{{ $testLabels[$test['testType']] }}: {{ $test['state'] === 'ready' ? 'Prasyarat terpenuhi' : 'Prasyarat belum terpenuhi' }}</li>
            @endforeach
        </ul>
    </section>
    <section class="consents" aria-labelledby="consent-heading">
        <h2 id="consent-heading">Persetujuan</h2>
        @if ($summary['consents']['legalReviewPending'])
            <p class="notice">Dokumen persetujuan masih menunggu tinjauan legal.</p>
        @endif
        @foreach (['psychotest', 'dass'] as $key)
            @php($label = $key === 'psychotest' ? 'Psikotes' : 'DASS-21 (wajib untuk paket ini)')
            <h3>{{ $label }}</h3>
            @if ($summary['consents'][$key]['state'] === 'accepted')
                <p>Persetujuan tercatat. Versi: {{ $summary['consents'][$key]['version'] }}</p>
            @elseif ($summary['consents'][$key]['state'] === 'required')
                <p>Persetujuan wajib untuk dokumen ini belum tercatat.{{ $confirmationReady ? ' Berikan persetujuan secara eksplisit pada formulir konfirmasi.' : ' Pilihan persetujuan tidak tersedia di halaman ringkasan ini.' }}</p>
                @if ($key === 'dass')
                    <p>Hasil DASS-21 tidak memengaruhi kelayakan dan tetap diproses terpisah dari penilaian psikotes.</p>
                @endif
                <h4>{{ $summary['consents'][$key]['document']['title'] }}</h4>
                <p>Versi: {{ $summary['consents'][$key]['document']['version'] }}</p>
                <p class="document-text">{{ $summary['consents'][$key]['document']['text'] }}</p>
            @endif
        @endforeach
    </section>
    @if ($confirmationReady)
        <form class="confirmation-form" data-checkout-confirmation method="post" action="{{ $confirmation['action'] }}">
            <input type="hidden" name="_checkout_csrf" value="{{ $checkoutCsrf }}">
            <h2>Lengkapi dan konfirmasi</h2>
            <p>Kolom berikut hanya memuat data profil wajib yang belum tersedia. Data ringkasan lainnya tidak akan dikirim oleh formulir ini.</p>
            @if ($missingProfileKeys !== [])
                <fieldset>
                    <legend>Data profil yang belum lengkap</legend>
                    <div class="form-grid">
                        @foreach ($missingProfileKeys as $key)
                        @php($descriptor = $confirmation['profile'][$key])
                        <div class="form-field">
                            <label for="checkout-profile-{{ $key }}">{{ $profileByKey[$key]['label'] }}</label>
                            @if ($descriptor['control'] === 'select')
                                <select id="checkout-profile-{{ $key }}" name="profile[{{ $key }}]" required>
                                    <option value="" selected disabled>Pilih {{ strtolower($profileByKey[$key]['label']) }}</option>
                                    @foreach ($descriptor['options'] as $option)
                                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="checkout-profile-{{ $key }}" name="profile[{{ $key }}]"
                                    type="{{ $descriptor['control'] }}" required
                                    @if (isset($descriptor['autocomplete'])) autocomplete="{{ $descriptor['autocomplete'] }}" @endif
                                    @if ($key === 'fullName') minlength="2" maxlength="200" @endif
                                    @if ($key === 'educationLevel') maxlength="64" @endif
                                    @if ($key === 'phone') maxlength="32" inputmode="tel" pattern="\+?[0-9][0-9 ()-]{7,30}" @endif>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </fieldset>
            @endif
            @if ($requiredConsentTypes !== [])
                <fieldset>
                    <legend>Persetujuan wajib</legend>
                    @foreach ($requiredConsentTypes as $type)
                        @php($label = $type === 'psychotest'
                            ? 'Saya menyetujui pelaksanaan psikotes sesuai dokumen di atas.'
                            : 'Saya menyetujui DASS-21 sebagai bagian wajib paket sesuai dokumen di atas.')
                    <input type="hidden" name="consents[{{ $type }}][documentVersion]" value="{{ $confirmation['consents'][$type]['documentVersion'] }}">
                    <input type="hidden" name="consents[{{ $type }}][documentHash]" value="{{ $confirmation['consents'][$type]['documentHash'] }}">
                    <label class="consent-choice" for="checkout-consent-{{ $type }}">
                        <input id="checkout-consent-{{ $type }}" type="checkbox" name="consents[{{ $type }}][accepted]" value="true" required>
                        <span>{{ $label }}</span>
                    </label>
                    @endforeach
                </fieldset>
            @endif
            <p class="transport-status" data-confirmation-status role="status" aria-live="polite" tabindex="-1">Formulir sedang disiapkan.</p>
            <noscript><p class="transport-warning">JavaScript diperlukan untuk mengirim konfirmasi sebagai JSON yang aman. Ringkasan tetap dapat dibaca.</p></noscript>
            <button type="submit" disabled aria-disabled="true">Simpan dan konfirmasi</button>
        </form>
        <script type="module" src="/js/checkout-confirmation-v1.js"></script>
    @endif
    <form method="post" action="/checkout/logout">
        <input type="hidden" name="_checkout_csrf" value="{{ $checkoutCsrf }}">
        <button type="submit">Keluar</button>
    </form>
</main>
<script type="application/json" id="checkout-summary-v2">{!! $summaryJson !!}</script>
</body>
</html>
