@if ($psychologist !== null)
    <section class="block psychologist">
        <h2>Tinjauan Psikolog <span class="jp">心理士確認</span></h2>
        <table class="kv">
            <tr>
                <th>Psikolog</th>
                <td>{{ $psychologist['name'] }}</td>
            </tr>
            <tr>
                <th>No. SILP</th>
                <td>{{ $psychologist['silp_number'] }}</td>
            </tr>
            <tr>
                <th>No. STR</th>
                <td>{{ $psychologist['str_number'] }}</td>
            </tr>
            <tr>
                <th>Fasilitas Layanan Psikologi</th>
                <td>{{ $psychologist['facility_name'] }}</td>
            </tr>
            <tr>
                <th>Alamat Fasilitas</th>
                <td>{{ $psychologist['facility_address'] }}</td>
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
