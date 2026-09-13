<x-filament-panels::page>
    <div class="min-w-0 space-y-6" data-fixture-id="{{ $fixture['fixtureId'] }}">
        <section class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" aria-labelledby="synthetic-heading">
            <h2 id="synthetic-heading" class="font-bold">DATA SINTETIS — BUKAN LAPORAN NYATA</h2>
            <p class="mt-1">Halaman ini hanya menguji kesiapan UI. Perubahan bersifat sementara, tidak disimpan, dan tidak membuat tanda tangan.</p>
        </section>

        @if ($fixture['validity']['status'] === 'V3')
            <section role="alert" data-validity-stop="V3" class="rounded-xl border-2 border-red-600 bg-red-50 p-5 text-red-950 dark:bg-red-950 dark:text-red-100" aria-labelledby="v3-heading">
                <h2 id="v3-heading" class="text-lg font-bold">STOP — laporan tidak dibuat</h2>
                <p class="mt-2">Validitas V3 menghentikan penerbitan. Jadwalkan ulang asesmen.</p>
                <ul class="mt-3 list-disc space-y-1 pl-5">
                    @foreach ($fixture['validity']['findings'] as $finding)
                        <li>{{ $finding }}</li>
                    @endforeach
                </ul>
                <p class="mt-3 font-mono text-sm">VALIDITY_V3</p>
            </section>
        @else
            <header class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $fixture['participant']['testNumber'] }} · {{ $fixture['case']['organizationLabel'] }}</p>
                <h2 class="mt-1 break-words text-xl font-semibold">{{ $fixture['participant']['displayName'] }}</h2>
                <p class="mt-1">Bidang tujuan {{ $fixture['case']['intendedField'] }} · Status {{ $fixture['report']['state'] }}</p>
            </header>

            <nav aria-label="Proyeksi review psikolog" class="flex min-w-0 flex-wrap gap-2">
                <button type="button" aria-controls="review-panel" aria-pressed="{{ $activePanel === 'hpp' ? 'true' : 'false' }}" class="min-h-11 rounded-lg border px-4 py-2 font-medium focus-visible:outline-2 focus-visible:outline-offset-2" wire:click="showPanel('hpp')">
                    Pratinjau HPP
                </button>
                <button type="button" aria-controls="review-panel" aria-pressed="{{ $activePanel === 'internal' ? 'true' : 'false' }}" class="min-h-11 rounded-lg border px-4 py-2 font-medium focus-visible:outline-2 focus-visible:outline-offset-2" wire:click="showPanel('internal')">
                    Bukti internal &amp; DASS
                </button>
            </nav>

            <section id="review-panel" tabindex="-1" class="min-w-0 space-y-6" aria-labelledby="projection-heading">
                @if ($activePanel === 'hpp')
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <h2 id="projection-heading" class="text-lg font-semibold">Pratinjau HPP</h2>
                        <p class="mt-1 text-sm">Proyeksi eksternal sintetis; bukan dokumen siap terbit.</p>

                        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div><dt class="font-medium">Nama</dt><dd>{{ $fixture['participant']['displayName'] }}</dd></div>
                            <div><dt class="font-medium">Bidang tujuan</dt><dd>{{ $fixture['case']['intendedField'] }}</dd></div>
                            <div><dt class="font-medium">IQ</dt><dd>{{ $fixture['eligibility']['iq'] }}</dd></div>
                            <div><dt class="font-medium">Rekomendasi draf</dt><dd>{{ $fixture['eligibility']['finalLabel'] }}</dd></div>
                        </dl>
                    </div>

                    <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                        @foreach ($fixture['hpp']['clusters'] as $cluster => $narrative)
                            <article class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <h3 class="font-semibold">Klaster {{ $cluster }}</h3>
                                <p class="mt-2 break-words" lang="id">{{ $narrative['id'] }}</p>
                                <p class="mt-2 break-words" lang="ja">{{ $narrative['jp'] }}</p>
                            </article>
                        @endforeach
                    </div>

                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="font-semibold">Kategori umum DASS-21</h3>
                        <p class="mt-2"><strong>{{ $fixture['hpp']['generalDass']['category'] }}</strong></p>
                        <p class="mt-1" lang="id">{{ $fixture['hpp']['generalDass']['narrativeId'] }}</p>
                        <p class="mt-1" lang="ja">{{ $fixture['hpp']['generalDass']['narrativeJp'] }}</p>
                    </div>
                @else
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <h2 id="projection-heading" class="text-lg font-semibold">Bukti internal psikolog</h2>
                        <p class="mt-1">Sumber level psikotes dan status validitas hanya untuk tinjauan profesional.</p>
                        <p class="mt-2 font-medium">Validitas {{ $fixture['validity']['status'] }} · Standar {{ $fixture['eligibility']['standardVersion'] }}</p>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700" tabindex="0" aria-label="Tabel aspek dan sumber level">
                        <table class="w-full min-w-[48rem] border-collapse text-left text-sm">
                            <caption class="p-4 text-left font-semibold">Sumber level psikotes</caption>
                            <thead><tr class="border-t border-gray-200 dark:border-gray-700"><th scope="col" class="p-3">Aspek</th><th scope="col" class="p-3">Sistem</th><th scope="col" class="p-3">Final</th><th scope="col" class="p-3">Zona</th><th scope="col" class="p-3">Sumber</th><th scope="col" class="p-3">Status G7</th></tr></thead>
                            <tbody>
                                @foreach ($fixture['eligibility']['aspects'] as $aspect)
                                    <tr class="border-t border-gray-200 align-top dark:border-gray-700">
                                        <th scope="row" class="p-3">{{ $aspect['code'] }} — {{ $aspect['label'] }} @if($aspect['critical'])<span class="block font-normal">Kritis</span>@endif</th>
                                        <td class="p-3">{{ $aspect['systemLevel'] }}</td>
                                        <td class="p-3">{{ $aspect['finalLevel'] }}</td>
                                        <td class="p-3">{{ $aspect['zone'] }}</td>
                                        <td class="p-3"><ul class="space-y-1">@foreach($aspect['sources'] as $source)<li>{{ $source['sourceCode'] }} · level {{ $source['level'] }} · {{ $source['sourceVersion'] }}</li>@endforeach</ul></td>
                                        <td class="p-3">{{ $aspect['g7']['state'] }} @if($aspect['g7']['required'])<span class="block">Selisih {{ $aspect['g7']['spread'] }}; narasi otomatis ditahan.</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                        <fieldset class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <legend class="px-1 font-semibold">Ubah level profesional (G6)</legend>
                            <p>Sistem A2: level 3. Nilai sistem tidak ditimpa.</p>
                            <label for="g6-final-level" class="mt-3 block font-medium">Level final A2</label>
                            <select id="g6-final-level" wire:model.live="g6FinalLevel" class="mt-1 min-h-11 w-full rounded-lg border-gray-300">
                                @foreach ([1, 2, 3, 4, 5] as $level)<option value="{{ $level }}">{{ $level }}</option>@endforeach
                            </select>
                            @if ($g6FinalLevel !== 3)
                                <label for="g6-reason" class="mt-3 block font-medium">Alasan perubahan (minimal 20 karakter)</label>
                                <textarea id="g6-reason" wire:model="g6Reason" rows="3" class="mt-1 w-full rounded-lg border-gray-300" aria-describedby="g6-count g6-error"></textarea>
                                <p id="g6-count" class="text-sm">{{ mb_strlen(trim($g6Reason)) }} karakter</p>
                                @error('g6Reason')<p id="g6-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @endif
                        </fieldset>

                        <fieldset class="min-w-0 rounded-xl border-2 border-amber-400 p-4">
                            <legend class="px-1 font-semibold">Resolusi G7 — C4</legend>
                            <p>Level sumber 2 dan 4 memiliki selisih 2. Narasi otomatis ditahan.</p>
                            <label for="g7-final-level" class="mt-3 block font-medium">Tetapkan level final</label>
                            <select id="g7-final-level" wire:model.live="g7FinalLevel" class="mt-1 min-h-11 w-full rounded-lg border-gray-300" aria-describedby="g7-level-error">
                                <option value="">Belum ditetapkan</option>
                                @foreach ([1, 2, 3, 4, 5] as $level)<option value="{{ $level }}">{{ $level }}</option>@endforeach
                            </select>
                            @error('g7FinalLevel')<p id="g7-level-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @if ($g7FinalLevel !== null && $g7FinalLevel !== 3)
                                <label for="g7-reason" class="mt-3 block font-medium">Alasan perubahan (minimal 20 karakter)</label>
                                <textarea id="g7-reason" wire:model="g7Reason" rows="3" class="mt-1 w-full rounded-lg border-gray-300" aria-describedby="g7-count g7-error"></textarea>
                                <p id="g7-count" class="text-sm">{{ mb_strlen(trim($g7Reason)) }} karakter</p>
                                @error('g7Reason')<p id="g7-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @endif
                        </fieldset>
                    </div>

                    <section class="rounded-xl border border-violet-300 p-4 dark:border-violet-700" aria-labelledby="dass-detail-heading">
                        <h3 id="dass-detail-heading" class="font-semibold">Skor subskala DASS-21</h3>
                        <p class="mt-1 font-medium">DASS-21 terpisah dan tidak memengaruhi level, zona, atau rekomendasi.</p>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                            @foreach ($fixture['internal']['dass']['subscales'] as $subscale)
                                <div><dt class="font-medium">{{ $subscale['label'] }}</dt><dd>{{ $subscale['score'] }} · {{ $subscale['category'] }}</dd></div>
                            @endforeach
                        </dl>
                    </section>

                    <button type="button" wire:click="validateDraft" wire:loading.attr="disabled" class="min-h-11 rounded-lg bg-primary-600 px-4 py-2 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2">
                        Validasi kesiapan sintetis
                    </button>
                @endif
            </section>

            <section aria-live="polite" data-signing-enabled="false" class="rounded-xl border border-gray-300 p-4 dark:border-gray-700" aria-labelledby="readiness-heading">
                <h2 id="readiness-heading" class="font-semibold">{{ $readinessMessage }}</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($blockingCodes as $code)<li><code>{{ $code }}</code></li>@endforeach
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" disabled aria-disabled="true" class="min-h-11 cursor-not-allowed rounded-lg border px-4 py-2 opacity-60">Tanda tangan belum tersedia</button>
                    <button type="button" disabled aria-disabled="true" class="min-h-11 cursor-not-allowed rounded-lg border px-4 py-2 opacity-60">Publikasi belum tersedia</button>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
