<x-filament-panels::page>
    <style>
        .scoring-failures-review table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .scoring-failures-review th,
        .scoring-failures-review td { border-top: 1px solid #e5e7eb; padding: .75rem 1rem; text-align: left; vertical-align: middle; }
        .scoring-failures-review th { font-weight: 600; background: #f9fafb; }
        .scoring-failures-review tbody tr:hover { background: #f3f4f6; }
        .scoring-failures-review .badge { display: inline-block; padding: .125rem .625rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        .scoring-failures-review .badge-failed { background: #fee2e2; color: #991b1b; }
        .scoring-failures-review .badge-not-scorable { background: #fef3c7; color: #92400e; }
        .scoring-failures-review .badge-review-required { background: #dbeafe; color: #1e40af; }
        .scoring-failures-review .filters { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; }
        .scoring-failures-review .filters label { display: block; font-size: .75rem; font-weight: 600; margin-bottom: .25rem; }
        .scoring-failures-review .filters select,
        .scoring-failures-review .filters input { border: 1px solid #d1d5db; border-radius: .5rem; padding: .375rem .625rem; font-size: .875rem; }
        .dark .scoring-failures-review th { background: #1f2937; }
        .dark .scoring-failures-review th,
        .dark .scoring-failures-review td { border-color: #374151; }
        .dark .scoring-failures-review tbody tr:hover { background: #1f2937; }
        .dark .scoring-failures-review .badge-failed { background: #7f1d1d; color: #fecaca; }
        .dark .scoring-failures-review .badge-not-scorable { background: #78350f; color: #fde68a; }
        .dark .scoring-failures-review .badge-review-required { background: #1e3a8a; color: #bfdbfe; }
        .dark .scoring-failures-review .filters select,
        .dark .scoring-failures-review .filters input { border-color: #4b5563; background: #111827; color: #f9fafb; }
    </style>

    <div class="scoring-failures-review space-y-6">
        <header>
            <h2 class="text-xl font-semibold">Kegagalan Penilaian</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Percobaan penilaian yang gagal atau tidak dapat dinilai, agar tidak hanya tercatat di database tanpa terlihat siapa pun.
                @unless ($canSeeReason)
                    Kode alasan hanya terlihat oleh psikolog.
                @endunless
            </p>
        </header>

        <form class="filters" wire:submit.prevent>
            <div>
                <label for="instrumentFilter">Instrumen</label>
                <select id="instrumentFilter" wire:model.live="instrumentFilter">
                    <option value="">Semua</option>
                    @foreach ($this->instrumentOptions() as $instrument)
                        <option value="{{ $instrument }}">{{ strtoupper($instrument) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="dateFrom">Dari tanggal</label>
                <input type="date" id="dateFrom" wire:model.live="dateFrom" />
            </div>
            <div>
                <label for="dateTo">Sampai tanggal</label>
                <input type="date" id="dateTo" wire:model.live="dateTo" />
            </div>
            @if ($canSeeReason)
                <div>
                    <label for="reasonCodeFilter">Kode alasan</label>
                    <select id="reasonCodeFilter" wire:model.live="reasonCodeFilter">
                        <option value="">Semua</option>
                        @foreach ($reasonCodeOptions as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </form>

        @if (empty($attempts))
            <div class="rounded-xl border border-gray-200 p-8 text-center dark:border-gray-700">
                <p class="text-gray-500 dark:text-gray-400">Tidak ada kegagalan penilaian sesuai filter saat ini.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700" tabindex="0" aria-label="Daftar kegagalan penilaian">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Waktu percobaan</th>
                            <th scope="col">Instrumen</th>
                            <th scope="col">Peserta</th>
                            <th scope="col">No. Tes</th>
                            <th scope="col">Sesi</th>
                            <th scope="col">Hasil</th>
                            @if ($canSeeReason)
                                <th scope="col">Alasan</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($attempts as $attempt)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($attempt['attempted_at'])->format('d M Y H:i') }}</td>
                                <td class="font-mono text-sm">{{ strtoupper($attempt['instrument_code']) }}</td>
                                <td class="font-medium">{{ $attempt['full_name'] }}</td>
                                <td class="font-mono text-sm">{{ $attempt['test_number'] }}</td>
                                <td class="font-mono text-sm">{{ $attempt['session_public_id'] }}</td>
                                <td>
                                    @if ($attempt['outcome'] === 'not_scorable')
                                        <span class="badge badge-not-scorable">Tidak dapat dinilai</span>
                                    @else
                                        <span class="badge badge-failed">Gagal dinilai</span>
                                    @endif
                                </td>
                                @if ($canSeeReason)
                                    <td class="font-mono text-sm">{{ $attempt['reason_code'] ?? '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <header>
            <h2 class="text-xl font-semibold">RMIB perlu ditinjau</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Hasil RMIB yang berhasil dinilai, tapi satu kelompok peringkat dikecualikan dari skor (ADR-0032 PR3) -- dibaca kualitatif, bukan gagal.
                @unless ($canSeeReason)
                    Kelompok yang dikecualikan hanya terlihat oleh psikolog.
                @endunless
            </p>
        </header>

        @if (empty($reviewRequiredResults))
            <div class="rounded-xl border border-gray-200 p-8 text-center dark:border-gray-700">
                <p class="text-gray-500 dark:text-gray-400">Tidak ada hasil RMIB yang perlu ditinjau sesuai filter saat ini.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700" tabindex="0" aria-label="Daftar hasil RMIB perlu ditinjau">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Waktu submit</th>
                            <th scope="col">Peserta</th>
                            <th scope="col">No. Tes</th>
                            <th scope="col">Sesi</th>
                            <th scope="col">Status</th>
                            @if ($canSeeReason)
                                <th scope="col">Kelompok dikecualikan</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reviewRequiredResults as $result)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($result['submitted_at'])->format('d M Y H:i') }}</td>
                                <td class="font-medium">{{ $result['full_name'] }}</td>
                                <td class="font-mono text-sm">{{ $result['test_number'] }}</td>
                                <td class="font-mono text-sm">{{ $result['session_public_id'] }}</td>
                                <td><span class="badge badge-review-required">Perlu ditinjau</span></td>
                                @if ($canSeeReason)
                                    <td class="font-mono text-sm">{{ implode(', ', $result['excluded_groups']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
