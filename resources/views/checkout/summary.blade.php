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
    <p class="intro">Halaman ini hanya menampilkan ringkasan. Perubahan profil, persetujuan, pembayaran, dan mulai tes belum tersedia di sini.</p>
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
            <p>Status ini berasal dari data checkout Anda. Pengisian dan persetujuan belum tersedia di halaman ringkasan ini.</p>
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
            <p>Sebagian data wajib belum lengkap; pengisian belum tersedia di halaman ini.</p>
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
                <p>Persetujuan wajib untuk dokumen ini belum tercatat. Pilihan persetujuan tidak tersedia di halaman ringkasan ini.</p>
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
    <form method="post" action="/checkout/logout">
        <input type="hidden" name="_checkout_csrf" value="{{ $checkoutCsrf }}">
        <button type="submit">Keluar</button>
    </form>
</main>
<script type="application/json" id="checkout-summary-v1">{!! $summaryJson !!}</script>
</body>
</html>
