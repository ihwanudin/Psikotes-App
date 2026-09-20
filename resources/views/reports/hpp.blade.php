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
            {{-- Owner decision 2026-09-20: show a dash, not a verdict. The short
                 note keeps it from reading as "no findings". --}}
            <p>Kategori umum: <strong>&ndash;</strong> <span class="jp">一般区分：&ndash;</span></p>
            <p>Hasil skrining tidak tersedia. <span class="jp">スクリーニング結果なし。</span></p>
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

    {{-- Wording taken verbatim from "Template Laporan HPP Psikotes.docx" (v2.3), Bagian V. --}}
    <section class="block">
        <h2>V. Batasan, Kerahasiaan &amp; Ketentuan Penggunaan <span class="jp">限界・守秘義務・使用条件</span></h2>
        <ol class="disclaimer">
            <li>
                <strong>Sifat hasil pemeriksaan</strong>
                <p>
                    Laporan ini merupakan gambaran fungsi psikologis peserta pada saat pemeriksaan dilakukan
                    ({{ $identity['test_date'] }}). Hasilnya bersifat prediktif-probabilistik, bukan kepastian
                    mengenai perilaku peserta di masa depan.
                </p>
                <p class="jp">本報告書は検査時点における心理的機能の記述です。結果は確率的な予測であり、将来の行動を確定するものではありません。</p>
            </li>
            <li>
                <strong>Kerahasiaan &amp; pelindungan data</strong>
                <p>
                    Data diproses berdasarkan persetujuan tertulis peserta. Peserta berhak memperoleh penjelasan
                    hasil, mengajukan koreksi data, dan menarik persetujuan sesuai UU No. 27 Tahun 2022. Retensi
                    data psikotes 5 tahun; retensi data skrining kesehatan mental 2 tahun, setelahnya dimusnahkan.
                </p>
                <p class="jp">データは受検者の書面同意に基づき処理されます。受検者は結果説明、データ訂正、同意撤回の権利を有します（2022年法律第27号）。心理検査データの保存期間は5年、精神健康スクリーニングデータは2年で、期間経過後は廃棄されます。</p>
            </li>
            <li>
                <strong>Larangan penggunaan</strong>
                <p>
                    Laporan tidak boleh digunakan di luar tujuan yang tercantum pada Bagian I, tidak boleh diubah
                    sebagian, dan tidak boleh disalin tanpa izin psikolog penanggung jawab serta fasilitas layanan
                    psikologi penerbit.
                </p>
                <p class="jp">第I部に記載された目的以外での使用、部分的な改変、担当心理士および発行機関の許可なき複製を禁じます。</p>
            </li>
        </ol>
        <p class="disclaimer">
            Dasar hukum dan etik penyelenggaraan: Undang-Undang No. 23 Tahun 2022 tentang Pendidikan dan Layanan
            Psikologi; Undang-Undang No. 18 Tahun 2017 tentang Pelindungan Pekerja Migran Indonesia; Undang-Undang
            No. 27 Tahun 2022 tentang Pelindungan Data Pribadi; serta Kode Etik Psikologi Indonesia.
        </p>
        <p class="disclaimer">
            Hasil skrining kesehatan mental pada Bagian III tidak digunakan sebagai dasar penetapan rekomendasi.
            <span class="jp">第III部の精神健康スクリーニング結果は、推薦判定の根拠として用いられません。</span>
        </p>
    </section>

    @include('reports.partials.psychologist', ['psychologist' => $psychologist])

    <footer>
        <p class="disclaimer">Dicetak dari draf template F6 — {{ $identity['report_number'] }} · standar {{ $identity['standard_version'] }}</p>
    </footer>
</div>
</body>
</html>
