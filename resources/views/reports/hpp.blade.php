<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Hasil Pemeriksaan Psikologis {{ $identity['report_number'] }}</title>
    <style>
        body { font-family: 'Noto Sans', 'Helvetica Neue', Arial, sans-serif; color: #1f2937; margin: 2rem; font-size: 12px; }
        .page { max-width: 210mm; margin: 0 auto; position: relative; }
        .draft-banner { border: 2px dashed #b45309; color: #b45309; padding: 6px 12px; text-align: center; font-weight: 700; margin-bottom: 12px; }
        h1 { font-size: 18px; margin: 0; }
        h2 { font-size: 14px; border-bottom: 2px solid #14532d; padding-bottom: 4px; margin-top: 24px; }
        h3 { font-size: 12px; margin: 14px 0 6px; }
        .jp { color: #4b5563; font-size: 10px; font-weight: 400; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px double #14532d; padding-bottom: 8px; }
        .header .org { font-weight: 700; color: #14532d; }
        table { border-collapse: collapse; width: 100%; margin-top: 8px; }
        .kv th { text-align: left; width: 16%; background: #f3f4f6; border: 1px solid #d1d5db; padding: 4px 6px; }
        .kv td { border: 1px solid #d1d5db; padding: 4px 6px; }
        .grid th, .data th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 4px 6px; text-align: center; }
        .grid td, .data td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: center; }
        .grid .aspect-col { text-align: left; width: 34%; }
        .zone-ok { background: #E7F1EA; }
        .zone-grey { background: #FBF0D9; }
        .zone-belum { background: #F7E6E6; }
        .zone-na { background: #ffffff; }
        .legend-ok { background: #E7F1EA; }
        .legend-grey { background: #FBF0D9; }
        .legend-belum { background: #F7E6E6; }
        .legend { margin-top: 6px; font-size: 10px; }
        .legend-chip { display: inline-block; border: 1px solid #d1d5db; padding: 1px 8px; margin-right: 8px; }
        .iq-summary { margin-top: 8px; font-size: 13px; }
        .cluster h3 { margin-bottom: 2px; }
        .cluster p { margin-top: 2px; text-align: justify; }
        .recommendation { border: 2px solid #14532d; padding: 10px 14px; margin-top: 8px; }
        .recommendation .label { font-size: 16px; font-weight: 700; color: #14532d; }
        .disclaimer { font-size: 10px; color: #4b5563; text-align: justify; }
        .psychologist .signature-line { margin-top: 28px; width: 60%; border-top: 1px solid #1f2937; padding-top: 4px; }
        @media print { .draft-banner { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="page">
    <div class="draft-banner">DRAF — DATA FIXTURE (F6) · BELUM DITINJAU PSIKOLOG · JANGAN DISTRIBUSIKAN</div>

    <div class="header">
        <div>
            <h1>Laporan Hasil Pemeriksaan Psikologis</h1>
            <span class="jp">心理検査結果報告書</span>
        </div>
        <div class="org">
            ONCAM Psikotes
            <span class="jp">オンキャム心理検査</span>
        </div>
    </div>

    @include('reports.partials.identity', ['identity' => $identity])

    @include('reports.partials.aspect-grid', ['aspect_rows' => $aspect_rows])

    <p class="iq-summary">Kategori Inteligensi Umum: <strong>{{ $iq['category'] }}</strong> <span class="jp">知的能力区分</span></p>

    <section class="block">
        <h2>Uraian per Klaster <span class="jp">総合所見</span></h2>
        @foreach ($cluster_narratives as $cluster => $narrative)
            <div class="cluster">
                <h3>{{ ['A' => 'Kemampuan Dasar', 'B' => 'Cara Kerja', 'C' => 'Kepribadian Kerja', 'D' => 'Minat Kerja'][$cluster] }}</h3>
                <p>{{ $narrative }}</p>
            </div>
        @endforeach
    </section>

    <section class="block">
        <h2>III. Skrining Kesehatan Mental <span class="jp">メンタルヘルス・スクリーニング</span></h2>
        @if ($dass === null)
            <p>Kategori umum: <strong>Tidak tersedia</strong> <span class="jp">一般区分：データなし</span></p>
            <p>
                Hasil skrining kesehatan mental tidak tersedia untuk peserta ini. Ketiadaan hasil bukan berarti tidak
                ditemukan keluhan, dan tidak memengaruhi penilaian kelayakan kerja.
            </p>
        @else
            <p>Kategori umum: <strong>{{ $dass['general_category'] }}</strong> <span class="jp">一般区分</span></p>
            <p>{{ $dass['narrative'] }}</p>
            @if ($dass['follow_up'] !== null)
                <p>{{ $dass['follow_up'] }}</p>
            @endif
        @endif
    </section>

    <section class="block">
        <h2>IV. Kesimpulan &amp; Rekomendasi <span class="jp">結論・推薦</span></h2>
        <div class="recommendation">
            <div class="label">{{ $recommendation['label'] }}</div>
            <p>{{ $recommendation['rationale'] }}</p>
            @if ($recommendation['accompaniment_conditions'] !== null)
                <p><strong>Syarat pendampingan:</strong> {{ $recommendation['accompaniment_conditions'] }}</p>
            @endif
        </div>
    </section>

    <section class="block">
        <h2>V. Batasan &amp; Kerahasiaan <span class="jp">限定事項・機密保持</span></h2>
        <p class="disclaimer">
            Laporan ini merupakan ringkasan hasil pemeriksaan psikologis pada tanggal {{ $identity['test_date'] }} dan
            menggambarkan kondisi peserta pada saat pemeriksaan. Hasil tidak dimaksudkan sebagai satu-satunya dasar
            keputusan penempatan. Dokumen bersifat rahasia dan hanya boleh disebarluaskan kepada pihak yang berhak
            sesuai kebijakan privasi yang berlaku. Skrining kesehatan mental pada bagian III tidak memengaruhi
            penilaian kelayakan kerja.
        </p>
    </section>

    @include('reports.partials.psychologist', ['psychologist' => $psychologist])

    <footer>
        <p class="disclaimer">Dicetak dari draf template F6 — {{ $identity['report_number'] }} · standar {{ $identity['standard_version'] }}</p>
    </footer>
</div>
</body>
</html>
