<section class="block">
    <h2>II. Hasil Pemeriksaan <span class="jp">検査結果</span></h2>

    <table class="grid">
        <thead>
            <tr>
                <th class="aspect-col">Aspek</th>
                <th>1</th>
                <th>2</th>
                <th>3</th>
                <th>4</th>
                <th>5</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($aspect_rows as $row)
                @php($zoneClass = $row['zone'] !== null ? 'zone-'.strtolower($row['zone']) : 'zone-na')
                <tr>
                    <td class="aspect-col">
                        {{ $row['label_id'] }}
                        <span class="jp">{{ $row['label_jp'] }}</span>
                    </td>
                    @foreach (range(1, 5) as $column)
                        <td class="{{ $zoneClass }}">{{ $column === $row['level'] ? '●' : '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="legend">
        <span class="legend-ok legend-chip">Terpenuhi (OK)</span>
        <span class="legend-grey legend-chip">Grey Area</span>
        <span class="legend-belum legend-chip">Belum Terpenuhi</span>
    </p>
</section>
