<x-filament-panels::page>
    <style>
        .psychologist-review-fixture { display: grid; gap: 1.5rem; min-width: 0; }
        .psychologist-review-fixture [class*="rounded-xl"][class*="border"] { border: 1px solid #d1d5db; border-radius: .75rem; background: #fff; padding: 1rem; }
        .psychologist-review-fixture [class*="border-2"] { border-width: 2px; border-color: #f59e0b; }
        .psychologist-review-fixture [data-synthetic-warning] { border-color: #f59e0b; background: #fffbeb; color: #78350f; }
        .psychologist-review-fixture [data-review-state="g7-unresolved"][aria-labelledby] { border: 2px solid #f59e0b; background: #fffbeb; color: #78350f; }
        .psychologist-review-fixture [data-review-state="g7-resolution"][data-review-prominent="true"] { border: 2px solid #f59e0b; }
        .psychologist-review-fixture [data-validity-stop="V3"][role="alert"] { border: 2px solid #dc2626; background: #fef2f2; color: #7f1d1d; }
        .psychologist-review-fixture #review-panel { display: grid; gap: 1.5rem; min-width: 0; }
        .psychologist-review-fixture nav { display: flex; flex-wrap: wrap; gap: .5rem; }
        .psychologist-review-fixture button { min-height: 2.75rem; border: 1px solid #9ca3af; border-radius: .5rem; padding: .5rem 1rem; }
        .psychologist-review-fixture button:hover { background: #f3f4f6; }
        .psychologist-review-fixture button:focus-visible,
        .psychologist-review-fixture select:focus-visible,
        .psychologist-review-fixture textarea:focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
        .psychologist-review-fixture select,
        .psychologist-review-fixture textarea { width: 100%; min-height: 2.75rem; border: 1px solid #9ca3af; border-radius: .5rem; background: #fff; padding: .625rem .75rem; color: #111827; }
        .psychologist-review-fixture textarea { min-height: 5.5rem; resize: vertical; }
        .psychologist-review-fixture [name="validate_fixture"] { border-color: #1d4ed8; background: #1d4ed8; color: #fff; font-weight: 700; }
        .psychologist-review-fixture [name="validate_fixture"]:hover { background: #1e40af; }
        .psychologist-review-fixture button:disabled { cursor: not-allowed; opacity: .6; }
        .psychologist-review-fixture [aria-label="Tabel aspek dan sumber level"] { overflow-x: auto; padding: 0; }
        .psychologist-review-fixture table { width: 100%; min-width: 48rem; border-collapse: collapse; }
        .psychologist-review-fixture th,
        .psychologist-review-fixture td { border-top: 1px solid #e5e7eb; padding: .75rem; text-align: left; vertical-align: top; }
        .psychologist-review-fixture [class*="grid"] { display: grid; gap: 1rem; min-width: 0; }
        .psychologist-review-fixture label { display: block; margin-top: .75rem; font-weight: 600; }
        @media (min-width: 640px) {
            .psychologist-review-fixture dl[class*="sm:grid-cols"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .psychologist-review-fixture #dass-detail-heading + p + dl { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 1024px) {
            .psychologist-review-fixture #review-panel > [class*="grid"],
            .psychologist-review-fixture fieldset > [class*="grid"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .psychologist-review-fixture #instrument-summary-heading + p + dl { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        .dark .psychologist-review-fixture [class*="rounded-xl"][class*="border"] { border-color: #4b5563; background: #111827; color: #f9fafb; }
        .dark .psychologist-review-fixture [data-synthetic-warning] { border-color: #d97706; background: #451a03; color: #fef3c7; }
        .dark .psychologist-review-fixture [data-review-state="g7-unresolved"][aria-labelledby] { border-color: #f59e0b; background: #451a03; color: #fef3c7; }
        .dark .psychologist-review-fixture [data-review-state="g7-resolution"][data-review-prominent="true"] { border-color: #f59e0b; }
        .dark .psychologist-review-fixture [data-validity-stop="V3"][role="alert"] { border-color: #ef4444; background: #450a0a; color: #fee2e2; }
        .dark .psychologist-review-fixture select,
        .dark .psychologist-review-fixture textarea { border-color: #6b7280; background: #111827; color: #f9fafb; }
    </style>
    <div
        class="psychologist-review-fixture min-w-0 space-y-6"
        data-fixture-id="{{ $fixture['fixtureId'] }}"
        x-data="{ draftTouched: false }"
        x-on:input="draftTouched = true"
        x-on:change="draftTouched = true"
        x-on:beforeunload.window="if (draftTouched) { $event.preventDefault(); $event.returnValue = '' }"
        x-on:review-focus.window="$nextTick(() => requestAnimationFrame(() => document.getElementById($event.detail.target)?.focus()))"
    >
        <section data-synthetic-warning class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" aria-labelledby="synthetic-heading">
            <h2 id="synthetic-heading" class="font-bold">DATA SINTETIS — BUKAN LAPORAN NYATA</h2>
            <p class="mt-1">Halaman ini hanya menguji kesiapan UI. Perubahan bersifat sementara, tidak disimpan, dan tidak membuat tanda tangan.</p>
            <p class="mt-1 font-medium">Perubahan draf akan hilang jika halaman dimuat ulang atau ditutup.</p>
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
                <p id="target-field-status" tabindex="-1" class="mt-1">Bidang tujuan {{ $fixture['case']['intendedField'] ?? 'belum ditetapkan' }} · Status {{ $fixture['report']['state'] }}</p>
            </header>

            <nav aria-label="Proyeksi review psikolog" class="flex min-w-0 flex-wrap gap-2">
                <button type="button" name="projection" value="hpp" aria-controls="review-panel" aria-pressed="{{ $activePanel === 'hpp' ? 'true' : 'false' }}" class="min-h-11 rounded-lg border px-4 py-2 font-medium hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:hover:bg-gray-800" wire:click="showPanel('hpp')" wire:loading.attr="disabled">
                    Pratinjau HPP
                </button>
                <button type="button" name="projection" value="internal" aria-controls="review-panel" aria-pressed="{{ $activePanel === 'internal' ? 'true' : 'false' }}" class="min-h-11 rounded-lg border px-4 py-2 font-medium hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 dark:hover:bg-gray-800" wire:click="showPanel('internal')" wire:loading.attr="disabled">
                    Bukti internal &amp; DASS
                </button>
            </nav>

            <section id="review-panel" tabindex="-1" class="min-w-0 space-y-6" aria-labelledby="projection-heading">
                @if ($activePanel === 'hpp')
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <h2 id="projection-heading" tabindex="-1" class="text-lg font-semibold">Pratinjau HPP</h2>
                        <p class="mt-1 text-sm">Proyeksi eksternal sintetis; bukan dokumen siap terbit.</p>

                        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div><dt class="font-medium">Nama</dt><dd>{{ $fixture['participant']['displayName'] }}</dd></div>
                            <div><dt class="font-medium">Bidang tujuan</dt><dd>{{ $fixture['case']['intendedField'] }}</dd></div>
                            <div><dt class="font-medium">IQ</dt><dd>{{ $fixture['eligibility']['iq'] }}</dd></div>
                            <div>
                                <dt class="font-medium">Rekomendasi sistem</dt>
                                <dd>{{ $fixture['eligibility']['systemLabel'] }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">Rekomendasi final</dt>
                                <dd>
                                    @if ($previewInvalidated)
                                        Pratinjau rekomendasi menunggu hitung ulang server
                                    @else
                                        Hasil hitung ulang server: {{ $fixture['eligibility']['finalLabel'] }}
                                    @endif
                                </dd>
                            </div>
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
                        <h2 id="projection-heading" tabindex="-1" class="text-lg font-semibold">Bukti internal psikolog</h2>
                        <p class="mt-1">Bukti psikotes dan status validitas ini hanya untuk tinjauan profesional.</p>
                        <p class="mt-2 font-medium">Validitas {{ $fixture['validity']['status'] }} · Standar {{ $fixture['eligibility']['standardVersion'] }}</p>
                    </div>

                    @if ($g7FinalLevel === null)
                        <section data-review-state="g7-unresolved" class="rounded-xl border-2 border-amber-400 p-4" aria-labelledby="g7-queue-heading">
                            <h3 id="g7-queue-heading" class="font-semibold">Antrean tinjauan G7 — belum terselesaikan</h3>
                            <ol class="mt-2 list-decimal pl-5">
                                <li><strong>C4 — Stres dan stabilitas</strong>: level sumber 2 dan 4, selisih 2; narasi otomatis ditahan.</li>
                            </ol>
                        </section>
                    @endif

                    <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="instrument-summary-heading">
                        <h3 id="instrument-summary-heading" class="font-semibold">Ringkasan instrumen sintetis</h3>
                        <p class="mt-1 text-sm">Bukti mentah sintetis untuk tinjauan internal psikolog.</p>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($fixture['internal']['instrumentSummaries'] as $summary)
                                <div><dt class="font-medium">{{ $summary['instrument'] }}</dt><dd>{{ $summary['summary'] }}</dd></div>
                            @endforeach
                        </dl>
                    </section>

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
                            <p class="mt-2 font-medium" role="status">
                                @if ($previewInvalidated)
                                    Pratinjau rekomendasi menunggu hitung ulang server
                                @else
                                    Hasil hitung ulang server: {{ $previewFinalLabel }}
                                @endif
                            </p>
                            <label for="g6-final-level" class="mt-3 block font-medium">Level final A2</label>
                            <select id="g6-final-level" name="g6_final_level" autocomplete="off" wire:model.live="g6FinalLevel" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 hover:border-gray-400">
                                @foreach ([1, 2, 3, 4, 5] as $level)<option value="{{ $level }}">{{ $level }}</option>@endforeach
                            </select>
                            @if ($g6FinalLevel !== 3)
                                <label for="g6-reason" class="mt-3 block font-medium">Alasan perubahan (minimal 20 karakter)</label>
                                <textarea id="g6-reason" name="g6_reason" autocomplete="off" wire:model="g6Reason" rows="3" class="mt-1 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="g6-count g6-error"></textarea>
                                <p id="g6-count" class="text-sm">{{ mb_strlen(trim($g6Reason)) }} karakter</p>
                                @error('g6Reason')<p id="g6-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @endif
                        </fieldset>

                        <fieldset data-review-state="g7-resolution" data-review-prominent="true" class="min-w-0 rounded-xl border-2 border-amber-400 p-4">
                            <legend class="px-1 font-semibold">Resolusi G7 — C4</legend>
                            <p>Level sumber 2 dan 4 memiliki selisih 2. Narasi otomatis ditahan.</p>
                            <label for="g7-final-level" class="mt-3 block font-medium">Tetapkan level final</label>
                            <select id="g7-final-level" name="g7_final_level" autocomplete="off" wire:model.live="g7FinalLevel" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="g7-level-error">
                                <option value="">Belum ditetapkan</option>
                                @foreach ([1, 2, 3, 4, 5] as $level)<option value="{{ $level }}">{{ $level }}</option>@endforeach
                            </select>
                            @error('g7FinalLevel')<p id="g7-level-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @if ($g7FinalLevel !== null && $g7FinalLevel !== 3)
                                <label for="g7-reason" class="mt-3 block font-medium">Alasan perubahan (minimal 20 karakter)</label>
                                <textarea id="g7-reason" name="g7_reason" autocomplete="off" wire:model="g7Reason" rows="3" class="mt-1 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="g7-count g7-error"></textarea>
                                <p id="g7-count" class="text-sm">{{ mb_strlen(trim($g7Reason)) }} karakter</p>
                                @error('g7Reason')<p id="g7-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @endif
                        </fieldset>
                    </div>

                    <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                        <fieldset class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <legend class="px-1 font-semibold">Validitas dan rekomendasi</legend>
                            <label for="procedure-note" class="block font-medium">Catatan prosedur @if($fixture['validity']['status'] === 'V2')(wajib untuk V2)@endif</label>
                            <textarea id="procedure-note" name="procedure_note" autocomplete="off" wire:model="procedureNote" rows="3" class="mt-1 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="procedure-note-error"></textarea>
                            @error('procedureNote')<p id="procedure-note-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror

                            <label for="accompaniment-conditions" class="mt-3 block font-medium">Syarat pendampingan (wajib untuk DIPERTIMBANGKAN)</label>
                            <textarea id="accompaniment-conditions" name="accompaniment_conditions" autocomplete="off" wire:model="accompanimentConditions" rows="3" class="mt-1 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="accompaniment-error"></textarea>
                            @error('accompanimentConditions')<p id="accompaniment-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                        </fieldset>

                        <fieldset class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <legend class="px-1 font-semibold">Override label profesional (G6)</legend>
                            <p>Label sistem: <strong>DIPERTIMBANGKAN</strong>. Nilai sistem tetap terlihat.</p>
                            <label for="label-final" class="mt-3 block font-medium">Label final</label>
                            <select id="label-final" name="label_final" autocomplete="off" wire:model.live="labelFinal" class="mt-1 min-h-11 w-full rounded-lg border-gray-300 hover:border-gray-400">
                                @foreach (['DISARANKAN', 'DIPERTIMBANGKAN', 'TIDAK_DISARANKAN'] as $label)<option value="{{ $label }}">{{ $label }}</option>@endforeach
                            </select>
                            @if ($labelFinal !== 'DIPERTIMBANGKAN')
                                <label for="label-reason" class="mt-3 block font-medium">Alasan perubahan label (minimal 20 karakter)</label>
                                <textarea id="label-reason" name="label_reason" autocomplete="off" wire:model="labelReason" rows="3" class="mt-1 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="label-reason-error"></textarea>
                                @error('labelReason')<p id="label-reason-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                            @endif
                        </fieldset>
                    </div>

                    <fieldset class="min-w-0 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <legend class="px-1 font-semibold">Draf narasi empat klaster</legend>
                        <div class="mt-2 grid gap-4 lg:grid-cols-2">
                            @foreach (['A', 'B', 'C', 'D'] as $cluster)
                                <div>
                                    <label for="cluster-{{ strtolower($cluster) }}-id" class="block font-medium">Klaster {{ $cluster }} bahasa Indonesia</label>
                                    <textarea id="cluster-{{ strtolower($cluster) }}-id" name="cluster_{{ strtolower($cluster) }}_id" autocomplete="off" wire:model="clusterDrafts.{{ $cluster }}" rows="3" class="mt-1 w-full rounded-lg border-gray-300 hover:border-gray-400" aria-describedby="cluster-{{ strtolower($cluster) }}-error"></textarea>
                                    @error("clusterDrafts.$cluster")<p id="cluster-{{ strtolower($cluster) }}-error" role="alert" class="text-sm text-red-700">{{ $message }}</p>@enderror
                                </div>
                            @endforeach
                        </div>
                    </fieldset>

                    <section class="rounded-xl border border-violet-300 p-4 dark:border-violet-700" aria-labelledby="dass-detail-heading">
                        <h3 id="dass-detail-heading" class="font-semibold">Skor subskala DASS-21</h3>
                        <p class="mt-1 font-medium">DASS-21 terpisah dan tidak memengaruhi level, zona, atau rekomendasi.</p>
                        <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                            @foreach ($fixture['internal']['dass']['subscales'] as $subscale)
                                <div><dt class="font-medium">{{ $subscale['label'] }}</dt><dd>{{ $subscale['score'] }} · {{ $subscale['category'] }}</dd></div>
                            @endforeach
                        </dl>
                    </section>

                    <button type="button" name="validate_fixture" wire:click="validateDraft" wire:loading.attr="disabled" class="min-h-11 rounded-lg bg-primary-600 px-4 py-2 font-semibold text-white hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2">
                        Validasi kesiapan sintetis
                    </button>
                @endif
            </section>

            <section aria-live="polite" data-signing-enabled="false" class="rounded-xl border border-gray-300 p-4 dark:border-gray-700" aria-labelledby="readiness-heading">
                <h2 id="readiness-heading" class="font-semibold">{{ $readinessMessage }}</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($blockingCodes as $code)
                        <li>
                            <button type="button" name="focus_blocker" value="{{ $code }}" wire:click="focusBlocker('{{ $code }}')" class="rounded font-mono text-left text-sm underline decoration-dotted hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2">
                                {{ $code }}
                            </button>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" disabled aria-disabled="true" class="min-h-11 cursor-not-allowed rounded-lg border px-4 py-2 opacity-60">Tanda tangan belum tersedia</button>
                    <button type="button" disabled aria-disabled="true" class="min-h-11 cursor-not-allowed rounded-lg border px-4 py-2 opacity-60">Publikasi belum tersedia</button>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
