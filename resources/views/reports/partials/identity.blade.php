<section class="block">
    <h2>I. Identitas &amp; Administrasi <span class="jp">基本情報</span></h2>
    <table class="kv">
        <tr>
            <th>No. Laporan</th>
            <td>{{ $identity['report_number'] }}</td>
            <th>No. Tes</th>
            <td>{{ $identity['test_number'] }}</td>
        </tr>
        <tr>
            <th>Nama</th>
            <td>{{ $identity['participant_name'] }}</td>
            <th>Tgl. Lahir</th>
            <td>{{ $identity['birth_date'] }}</td>
        </tr>
        <tr>
            <th>Pendidikan</th>
            <td>{{ $identity['education'] }}</td>
            <th>Tgl. Tes</th>
            <td>{{ $identity['test_date'] }}</td>
        </tr>
        <tr>
            <th>LPK / Cabang</th>
            <td>{{ $identity['branch_name'] }}</td>
            <th>Bidang Tujuan</th>
            <td>{{ $identity['target_field'] }}</td>
        </tr>
        @if ($showStandard ?? true)
            <tr>
                <th>Versi Standar</th>
                <td colspan="3">{{ $identity['standard_version'] }}</td>
            </tr>
        @endif
    </table>
</section>
