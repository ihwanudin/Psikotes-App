<section class="block">
    <h2>Blok Instrumen <span class="jp">検査ブロック</span></h2>

    <h3>IST — Intelligenz Struktur Test</h3>
    <p class="meta">IQ {{ $ist['iq'] }} ({{ $ist['iq_category'] }})</p>
    <table class="data">
        <thead>
            <tr><th>Subtes</th><th>SW</th><th>Level</th><th>Kategori</th></tr>
        </thead>
        <tbody>
            @foreach ($ist['subtests'] as $code => $subtest)
                <tr>
                    <td>{{ $code }}</td>
                    <td>{{ $subtest['sw'] }}</td>
                    <td>{{ $subtest['level'] }}</td>
                    <td>{{ $subtest['label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>Kraepelin — norma {{ $kraepelin['norm_group'] }}</h3>
    <table class="data">
        <thead>
            <tr><th>Faktor</th><th>Nilai</th><th>Level</th><th>Band</th></tr>
        </thead>
        <tbody>
            @foreach ($kraepelin['factors'] as $code => $factor)
                <tr>
                    <td>{{ $code }}</td>
                    <td>{{ $factor['value'] }}</td>
                    <td>{{ $factor['level'] }}</td>
                    <td>{{ $factor['band'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>RMIB — peringkat minat (1 = paling diminati)</h3>
    <table class="data">
        <thead>
            <tr><th>Peringkat</th><th>Kategori</th><th>Aspek HPP</th></tr>
        </thead>
        <tbody>
            @foreach ($rmib as $category)
                <tr>
                    <td>{{ $category['rank'] }}</td>
                    <td>{{ $category['label'] }}</td>
                    <td>{{ $category['d_aspect'] ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3>PAPI Kostick — 20 skala (mentah 0–9)</h3>
    <table class="data">
        <thead>
            <tr><th>Skala</th><th>Mentah</th><th>Level</th><th>Keterangan</th></tr>
        </thead>
        <tbody>
            @foreach ($papi as $code => $scale)
                <tr>
                    <td>{{ $code }}</td>
                    <td>{{ $scale['raw'] }}</td>
                    <td>{{ $scale['level'] ?? '—' }}</td>
                    <td>{{ $scale['qualitative'] ? 'kualitatif' : 'dipakai' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
