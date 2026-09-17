@if ($psychologist !== null)
    <section class="block psychologist">
        <h2>Tinjauan Psikolog <span class="jp">心理士確認</span></h2>
        <table class="kv">
            <tr>
                <th>Psikolog</th>
                <td>{{ $psychologist['name'] }}</td>
            </tr>
            <tr>
                <th>No. SIPP</th>
                <td>{{ $psychologist['sipp_number'] }}</td>
            </tr>
            @if ($psychologist['signature_note'] !== null)
                <tr>
                    <th>Catatan</th>
                    <td>{{ $psychologist['signature_note'] }}</td>
                </tr>
            @endif
        </table>
        <p class="signature-line">Tanda tangan elektronik menyusul setelah tinjauan final.</p>
    </section>
@endif
