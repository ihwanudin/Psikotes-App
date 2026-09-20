<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Lembar Kerja Internal Psikolog {{ $identity['report_number'] }}</title>
    <style>
        body { font-family: 'Noto Sans', 'Helvetica Neue', Arial, sans-serif; color: #1f2937; margin: 2rem; font-size: 12px; }
        .page { max-width: 210mm; margin: 0 auto; }
        .draft-banner { border: 2px dashed #9ca3af; color: #4b5563; padding: 6px 12px; text-align: center; font-weight: 700; margin-bottom: 12px; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 14px; border-bottom: 2px solid #1e3a8a; padding-bottom: 4px; margin-top: 24px; }
        h3 { font-size: 12px; margin: 14px 0 6px; }
        .jp { color: #4b5563; font-size: 10px; font-weight: 400; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px double #1e3a8a; padding-bottom: 8px; }
        .header .org { font-weight: 700; color: #1e3a8a; }
        .internal-only { color: #b91c1c; font-weight: 700; }
        table { border-collapse: collapse; width: 100%; margin-top: 8px; }
        .kv th { text-align: left; width: 16%; background: #f3f4f6; border: 1px solid #d1d5db; padding: 4px 6px; }
        .kv td { border: 1px solid #d1d5db; padding: 4px 6px; }
        .data th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 4px 6px; text-align: center; }
        .data td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: center; }
        .zone-ok { background: #E7F1EA; }
        .zone-grey { background: #FBF0D9; }
        .zone-belum { background: #F7E6E6; }
        .meta { color: #4b5563; font-size: 10px; margin: 2px 0; }
        .slots li { margin-bottom: 8px; }
        .slots p { margin: 2px 0; }
        ul.notes li { margin-bottom: 4px; }
        .psychologist .signature-line { margin-top: 28px; width: 60%; border-top: 1px solid #1f2937; padding-top: 4px; }
    </style>
</head>
<body>
<div class="page">
    <div class="draft-banner">LEMBAR KERJA INTERNAL — DATA FIXTURE (F6) · KHUSUS PSIKOLOG · JANGAN DIKIRIM KE PESERTA/LPK/KUMIAI</div>

    <div class="header">
        <div>
            <h1>Lembar Kerja Internal Psikolog</h1>
            <span class="jp">心理士社内ワークシート</span>
        </div>
        <div class="org">
            ONCAM Psikotes
            <span class="internal-only">INTERNAL ONLY</span>
        </div>
    </div>

    @include('reports.partials.identity', ['identity' => $identity])

    <section class="block">
        <h2>Validitas Sesi <span class="jp">セッションの妥当性</span></h2>
        <p>Status: <strong>{{ $validity['status'] }}</strong>@if($validity['procedure_note'] !== null) — catatan prosedur: {{ $validity['procedure_note'] }}@endif</p>
        <table class="data">
            <thead>
                <tr><th>Butir Pemeriksaan</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($validity['items'] as $item => $passed)
                    <tr>
                        <td>{{ \App\Domain\Report\SessionValiditySummary::ITEM_LABELS[$item] }}</td>
                        <td>{{ $passed ? 'LULUS' : 'PERIKSA' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="block">
        <h2>Ringkasan Zona <span class="jp">ゾーン集計</span></h2>
        <p>Terpenuhi: {{ $zone_counts['OK'] }} · Grey Area: {{ $zone_counts['GREY'] }} · Belum: {{ $zone_counts['BELUM'] }}</p>
        <table class="data">
            <thead>
                <tr>
                    <th>Kode</th><th>Aspek</th><th>Level</th><th>Standar</th><th>Zona</th><th>Kritis</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($aspect_rows as $row)
                    @php($zoneClass = $row['zone'] !== null ? 'zone-'.strtolower($row['zone']) : 'zone-ok')
                    <tr>
                        <td>{{ $row['code'] }}</td>
                        <td style="text-align: left">{{ $row['label_id'] }}</td>
                        <td>{{ $row['level'] }}</td>
                        <td>{{ $row['standard'] ?? '—' }}</td>
                        <td class="{{ $zoneClass }}">{{ $row['zone'] ?? 'N/A' }}</td>
                        <td>{{ $row['critical'] ? 'YA' : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @include('reports.partials.instrument-blocks', [
        'ist' => $ist,
        'kraepelin' => $kraepelin,
        'papi' => $papi,
        'rmib' => $rmib,
    ])

    @include('reports.partials.integration-slots', ['integration_slots' => $integration_slots])

    <section class="block">
        <h2>Hasil Rinci DASS-21 <span class="jp">DASS-21詳細</span></h2>
        <p>Kategori umum: <strong>{{ $dass_detail['general_category'] }}</strong> — {{ $dass_detail['follow_up'] }}</p>
        <table class="data">
            <thead>
                <tr><th>Subskala</th><th>Mentah</th><th>×2</th><th>Kategori</th></tr>
            </thead>
            <tbody>
                @foreach ($dass_detail['subscales'] as $code => $subscale)
                    <tr>
                        <td>{{ ['d' => 'Depresi', 'a' => 'Kecemasan', 's' => 'Stres'][$code] }}</td>
                        <td>{{ $subscale['raw'] }}</td>
                        <td>{{ $subscale['doubled'] }}</td>
                        <td>{{ $subscale['category'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (count($dass_detail['flags']) > 0)
            <p>Penanda validitas respons: {{ implode('; ', $dass_detail['flags']) }}</p>
        @endif
    </section>

    <section class="block">
        <h2>Catatan Tinjauan <span class="jp">レビューメモ</span></h2>
        <ul class="notes">
            @foreach ($review_notes as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
    </section>

    @include('reports.partials.psychologist', ['psychologist' => $psychologist])
</div>
</body>
</html>

