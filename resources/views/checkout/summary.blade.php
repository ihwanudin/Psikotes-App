<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="checkout-csrf-token" content="{{ $checkoutCsrf }}">
    <title>Ringkasan assessment</title>
</head>
<body>
<main>
    <h1>Ringkasan assessment</h1>
    <p>Halaman ini hanya menampilkan ringkasan. Perubahan profil, persetujuan, pembayaran, dan mulai tes belum tersedia di sini.</p>
    <section aria-labelledby="assessment-heading">
        <h2 id="assessment-heading">{{ $summary['attemptLabel'] }}</h2>
        <dl>
            <dt>Sumber</dt><dd>{{ $summary['sourceName'] }}</dd>
            <dt>Cabang</dt><dd>{{ $summary['branchName'] }}</dd>
            <dt>Paket</dt><dd>{{ $summary['packageName'] }}</dd>
            <dt>Sumber informasi paket</dt><dd>{{ $summary['packageSource'] }}</dd>
        </dl>
    </section>
    <section aria-labelledby="profile-heading">
        <h2 id="profile-heading">Profil Anda</h2>
        <dl>
            @foreach ($summary['profile'] as $field)
                <dt>{{ $field['label'] }}{{ $field['required'] ? ' (wajib)' : ' (opsional)' }}</dt>
                <dd>{{ $field['displayValue'] ?? 'Belum tersedia' }}</dd>
            @endforeach
        </dl>
        <p>{{ $summary['identityMessage'] }}</p>
    </section>
    <section aria-labelledby="payment-heading">
        <h2 id="payment-heading">Pembayaran</h2>
        <dl>
            <dt>Pembayar</dt><dd>{{ $summary['payment']['payer'] }}</dd>
            @if (isset($summary['payment']['organizationName']))
                <dt>Organisasi</dt><dd>{{ $summary['payment']['organizationName'] }}</dd>
            @endif
            <dt>Status</dt><dd>{{ $summary['payment']['state'] }}</dd>
            <dt>Nominal Anda</dt><dd>{{ $summary['payment']['amountIdr'] === null ? 'Belum tersedia' : 'Rp '.number_format($summary['payment']['amountIdr'], 0, ',', '.') }}</dd>
            <dt>Sumber nominal</dt><dd>{{ $summary['payment']['amountSource'] }}</dd>
            <dt>Konsultasi diminta</dt><dd>{{ $summary['payment']['consultationRequested'] === null ? 'Belum tersedia' : ($summary['payment']['consultationRequested'] ? 'Ya' : 'Tidak') }}</dd>
        </dl>
    </section>
    <section aria-labelledby="access-heading">
        <h2 id="access-heading">Akses tes</h2>
        <p>{{ $summary['access']['message'] }}</p>
        <p>Status: {{ $summary['access']['state'] }}</p>
        <ul>
            @foreach ($summary['access']['tests'] as $test)
                <li>{{ $test['testType'] }}: {{ $test['state'] }}</li>
            @endforeach
        </ul>
    </section>
    <section aria-labelledby="consent-heading">
        <h2 id="consent-heading">Persetujuan</h2>
        @if ($summary['consents']['legalReviewPending'])
            <p>Dokumen persetujuan masih menunggu tinjauan legal.</p>
        @endif
        @foreach (['psychotest' => 'Psikotes', 'dass' => 'DASS (opsional)'] as $key => $label)
            <h3>{{ $label }}</h3>
            @if ($summary['consents'][$key]['state'] === 'accepted')
                <p>Disetujui. Versi: {{ $summary['consents'][$key]['version'] }}</p>
            @elseif ($summary['consents'][$key]['state'] === 'required')
                <p>Persetujuan belum tersedia untuk dokumen berikut.</p>
                <h4>{{ $summary['consents'][$key]['document']['title'] }}</h4>
                <p>Versi: {{ $summary['consents'][$key]['document']['version'] }}</p>
                <p>{{ $summary['consents'][$key]['document']['text'] }}</p>
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
