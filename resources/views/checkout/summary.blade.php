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
    $confirmationReady = $confirmation !== null
        && is_string($confirmation['action'] ?? null)
        && preg_match('#^/checkout/[a-z0-9-]+$#D', $confirmation['action']) === 1
        && $confirmationProfileKeys === $missingProfileKeys
        && $psychotestConsentRequired && $dassConsentRequired
        && ! $summary['consents']['legalReviewPending'];
    foreach (['psychotest', 'dass'] as $type) {
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
            <p>Status ini berasal dari data checkout Anda. {{ $confirmationReady ? 'Lengkapi hanya data yang masih kosong dan kedua persetujuan di bawah.' : 'Pengisian dan persetujuan belum tersedia di halaman ringkasan ini.' }}</p>
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
            @php
                $label = $key === 'psychotest'
                    ? 'Psikotes'
                    : ($summary['consents']['dass']['state'] === 'not_applicable' ? 'DASS-21' : 'DASS-21 (wajib untuk paket ini)');
            @endphp
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
            @else
                <p>Tidak berlaku untuk paket ini.</p>
            @endif
        @endforeach
    </section>
    @if ($confirmationReady)
        <form class="confirmation-form" data-checkout-confirmation method="post" action="{{ $confirmation['action'] }}">
            <input type="hidden" name="_checkout_csrf" value="{{ $checkoutCsrf }}">
            <h2>Lengkapi dan konfirmasi</h2>
            <p>Kolom berikut hanya memuat data profil wajib yang belum tersedia. Data ringkasan lainnya tidak akan dikirim oleh formulir ini.</p>
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
            <fieldset>
                <legend>Persetujuan wajib</legend>
                @foreach (['psychotest' => 'Saya menyetujui pelaksanaan psikotes sesuai dokumen di atas.',
                    'dass' => 'Saya menyetujui DASS-21 sebagai bagian wajib paket sesuai dokumen di atas.'] as $type => $label)
                    <input type="hidden" name="consents[{{ $type }}][documentVersion]" value="{{ $confirmation['consents'][$type]['documentVersion'] }}">
                    <input type="hidden" name="consents[{{ $type }}][documentHash]" value="{{ $confirmation['consents'][$type]['documentHash'] }}">
                    <label class="consent-choice" for="checkout-consent-{{ $type }}">
                        <input id="checkout-consent-{{ $type }}" type="checkbox" name="consents[{{ $type }}][accepted]" value="true" required>
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </fieldset>
            <button type="submit">Simpan dan konfirmasi</button>
        </form>
    @endif
    <form method="post" action="/checkout/logout">
        <input type="hidden" name="_checkout_csrf" value="{{ $checkoutCsrf }}">
        <button type="submit">Keluar</button>
    </form>
</main>
<script type="application/json" id="checkout-summary-v1">{!! $summaryJson !!}</script>
</body>
</html>
