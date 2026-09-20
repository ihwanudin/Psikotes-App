<x-filament-panels::page>
    <style>
        .report-signing-queue table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .report-signing-queue th,
        .report-signing-queue td { border-top: 1px solid #e5e7eb; padding: .75rem 1rem; text-align: left; vertical-align: middle; }
        .report-signing-queue th { font-weight: 600; background: #f9fafb; }
        .report-signing-queue tbody tr:hover { background: #f3f4f6; }
        .report-signing-queue .badge { display: inline-block; padding: .125rem .625rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        .report-signing-queue .badge-signed { background: #d1fae5; color: #065f46; }
        .report-signing-queue .badge-pending { background: #fef3c7; color: #92400e; }
        .dark .report-signing-queue th { background: #1f2937; }
        .dark .report-signing-queue th,
        .dark .report-signing-queue td { border-color: #374151; }
        .dark .report-signing-queue tbody tr:hover { background: #1f2937; }
        .dark .report-signing-queue .badge-signed { background: #064e3b; color: #a7f3d0; }
        .dark .report-signing-queue .badge-pending { background: #78350f; color: #fde68a; }
    </style>

    <div class="report-signing-queue space-y-6">
        <header>
            <h2 class="text-xl font-semibold">Daftar Kasus — Tanda Tangan Laporan</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Kasus yang telah menyelesaikan asesmen dan siap ditinjau atau ditandatangani.</p>
        </header>

        @if (empty($this->cases))
            <div class="rounded-xl border border-gray-200 p-8 text-center dark:border-gray-700">
                <p class="text-gray-500 dark:text-gray-400">Tidak ada kasus yang siap ditinjau saat ini.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700" tabindex="0" aria-label="Daftar kasus tanda tangan laporan">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Peserta</th>
                            <th scope="col">No. Tes</th>
                            <th scope="col">Cabang</th>
                            <th scope="col">Bidang Tujuan</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Tindakan</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->cases as $case)
                            <tr>
                                <td class="font-medium">{{ $case['full_name'] }}</td>
                                <td class="font-mono text-sm">{{ $case['test_number'] }}</td>
                                <td>{{ $case['branch_name'] ?? '—' }}</td>
                                <td>{{ $case['intended_field_snapshot'] ?? '—' }}</td>
                                <td>
                                    @if (empty($case['signing_version']))
                                        <span class="badge badge-pending">Belum ditandatangani</span>
                                    @elseif ((int) $case['signing_version'] === 1)
                                        <span class="badge badge-signed">Ditandatangani</span>
                                    @else
                                        <span class="badge badge-signed">Direvisi (v{{ $case['signing_version'] }})</span>
                                    @endif
                                </td>
                                <td>
                                    <a
                                        href="{{ \App\Filament\Pages\ReportSigning::getUrl(['case' => $case['public_id']]) }}"
                                        class="inline-block rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:border-gray-600 dark:text-primary-400 dark:hover:bg-primary-900/20"
                                    >
                                        Tinjau & Tanda Tangan
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
