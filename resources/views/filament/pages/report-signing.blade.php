<x-filament-panels::page>
    <style>
        .report-signing { display: grid; gap: 1.5rem; min-width: 0; }
        .report-signing section,
        .report-signing fieldset { border: 1px solid #d1d5db; border-radius: .75rem; background: #fff; padding: 1rem; }
        .report-signing section[aria-labelledby] { min-width: 0; }
        .report-signing button { min-height: 2.75rem; border: 1px solid #9ca3af; border-radius: .5rem; padding: .5rem 1rem; }
        .report-signing button:hover { background: #f3f4f6; }
        .report-signing button:focus-visible,
        .report-signing select:focus-visible,
        .report-signing textarea:focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
        .report-signing select,
        .report-signing textarea { width: 100%; min-height: 2.75rem; border: 1px solid #9ca3af; border-radius: .5rem; background: #fff; padding: .625rem .75rem; color: #111827; }
        .report-signing textarea { min-height: 5.5rem; resize: vertical; }
        .report-signing button:disabled { cursor: not-allowed; opacity: .6; }
        .report-signing table { width: 100%; min-width: 56rem; border-collapse: collapse; font-size: .875rem; }
        .report-signing th,
        .report-signing td { border-top: 1px solid #e5e7eb; padding: .5rem .75rem; text-align: left; vertical-align: top; }
        .report-signing th { font-weight: 600; }
        .report-signing [role="alert"] { border-color: #f59e0b; background: #fffbeb; color: #78350f; }
        .report-signing [data-blocker] { font-family: monospace; font-size: .75rem; }
        .dark .report-signing section,
        .dark .report-signing fieldset { border-color: #4b5563; background: #111827; color: #f9fafb; }
        .dark .report-signing select,
        .dark .report-signing textarea { border-color: #6b7280; background: #111827; color: #f9fafb; }
    </style>

    <div
        class="report-signing"
        x-data="{ draftTouched: false }"
        x-on:input="draftTouched = true"
        x-on:change="draftTouched = true"
        x-on:review-focus.window="$nextTick(() => requestAnimationFrame(() => document.getElementById($event.detail.target)?.focus()))"
    >
        {{-- Header --}}
        <header class="rounded-xl border border-gray-200 p-4 dark:border-gray-700" id="case-header">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $participantLabel }} · {{ $caseLabel }}</p>
            <h2 class="mt-1 text-xl font-semibold">Laporan — {{ $caseLabel }}</h2>
            <p class="mt-1">
                Bidang tujuan {{ $intendedField ?? 'belum ditetapkan' }}
                · Validitas {{ $validity }}
                · Rekomendasi sistem <strong>{{ $systemLabel }}</strong>
                · IQ {{ $iq }}
            </p>
        </header>

        @if ($isReadOnly)
            {{-- Read-only display --}}
            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="signed-heading">
                <h2 id="signed-heading" class="text-lg font-semibold text-green-700 dark:text-green-400">Laporan sudah ditandatangani</h2>
                <p class="mt-2">Snapshot berikut bersifat append-only dan tidak dapat diubah.</p>

                @if ($existingSnapshot)
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="font-medium">Label final</dt>
                            <dd>{{ $existingSnapshot['prerequisite_input']['label'] ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Validitas</dt>
                            <dd>{{ $existingSnapshot['prerequisite_input']['validity'] ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Bidang tujuan</dt>
                            <dd>{{ $existingSnapshot['prerequisite_input']['target_field'] ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Catatan prosedur</dt>
                            <dd>{{ $existingSnapshot['prerequisite_input']['procedure_note'] ?? '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium">Syarat pendampingan</dt>
                            <dd>{{ $existingSnapshot['prerequisite_input']['accompaniment_conditions'] ?? '-' }}</dd>
                        </div>
                    </dl>

                    <h3 class="mt-6 font-semibold">Narasi klaster</h3>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        @foreach (['A', 'B', 'C', 'D'] as $cluster)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <span class="font-medium">Klaster {{ $cluster }}</span>
                                <p class="mt-1">{{ $existingSnapshot['prerequisite_input']['narrative_clusters'][$cluster] ?? '-' }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if (!empty($existingSnapshot['prerequisite_input']['overrides']))
                        <h3 class="mt-6 font-semibold">Override diterapkan</h3>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($existingSnapshot['prerequisite_input']['overrides'] as $override)
                                <li>
                                    {{ $override['type'] === 'label' ? 'Label' : 'Aspek '.$override['aspect'] }}
                                    — {{ $override['reason'] ?? 'Tanpa alasan' }}
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (!empty($existingSnapshot['revision']))
                        <h3 class="mt-6 font-semibold">Riwayat revisi</h3>
                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900">
                            <p class="text-sm">Versi ini merevisi snapshot versi {{ $existingSnapshot['revision']['supersedes_version'] ?? '?' }}.</p>
                            <p class="mt-1 text-sm font-medium">Alasan: {{ $existingSnapshot['revision']['reason'] ?? '-' }}</p>
                        </div>
                    @endif
                @endif

                {{-- Revision request section --}}
                <div class="mt-6 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <h3 class="font-semibold">Ajukan Revisi</h3>
                    <p class="mt-1 text-sm">Untuk merevisi laporan yang sudah ditandatangani, isi alasan revisi di bawah.</p>
                    <label for="revision-reason" class="mt-3 block font-medium">Alasan revisi (minimal 20 karakter)</label>
                    <textarea
                        id="revision-reason"
                        wire:model="revisionReason"
                        rows="3"
                        class="mt-1"
                        placeholder="Jelaskan mengapa laporan perlu direvisi..."
                    ></textarea>
                    @error('revisionReason')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
                    <button
                        type="button"
                        wire:click="requestRevision"
                        class="mt-3 rounded-lg bg-amber-600 px-4 py-2 font-semibold text-white hover:bg-amber-500"
                    >
                        Ajukan Revisi
                    </button>
                </div>
            </section>
        @else
            {{-- Revision banner --}}
            @if ($isRevision)
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-600 dark:bg-amber-900" role="alert">
                    <p class="font-semibold text-amber-800 dark:text-amber-200">Mode revisi</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">Anda sedang merevisi laporan yang sudah ditandatangani. Versi baru akan disimpan sebagai versi terpisah dan tidak mengubah versi sebelumnya.</p>
                </div>
            @endif

            {{-- Aspect table --}}
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700" tabindex="0" aria-label="Tabel aspek dan override">
                <table>
                    <caption class="p-4 text-left font-semibold">Level per aspek dan override profesional</caption>
                    <thead>
                        <tr>
                            <th scope="col">Aspek</th>
                            <th scope="col">Level sistem</th>
                            <th scope="col">Level final</th>
                            <th scope="col">Alasan override</th>
                            <th scope="col">G7 — Sumber</th>
                            <th scope="col">G7 — Level final</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($aspects as $aspect)
                            <tr>
                                <th scope="row">
                                    {{ $aspect['code'] }} — {{ $aspect['label'] }}
                                    @if ($aspect['g7Required'])
                                        <span class="block text-amber-600 dark:text-amber-400">G7 diperlukan</span>
                                    @endif
                                </th>
                                <td>{{ $aspect['systemLevel'] }}</td>
                                <td>
                                    <select
                                        wire:model.live="levelOverrides.{{ $aspect['code'] }}.final_level"
                                        class="min-h-9 w-20 text-sm"
                                    >
                                        @foreach ([1, 2, 3, 4, 5] as $level)
                                            <option value="{{ $level }}">{{ $level }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    @if ($aspect['changed'])
                                        <textarea
                                            wire:model="levelOverrides.{{ $aspect['code'] }}.reason"
                                            rows="2"
                                            class="text-sm"
                                            placeholder="Alasan minimal 20 karakter"
                                        ></textarea>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td>
                                    <ul class="space-y-1 text-xs">
                                        @foreach ($aspect['g7Sources'] as $source)
                                            <li>{{ $source['source'] }} · level {{ $source['level'] }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td>
                                    @if ($aspect['g7Required'])
                                        <select
                                            wire:model.live="g7Resolutions.{{ $aspect['code'] }}.final_level"
                                            class="min-h-9 w-20 text-sm"
                                        >
                                            <option value="">—</option>
                                            @foreach ([1, 2, 3, 4, 5] as $level)
                                                <option value="{{ $level }}">{{ $level }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-gray-400">Tidak diperlukan</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Label override --}}
            <fieldset class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <legend class="px-1 font-semibold">Override label rekomendasi</legend>
                <p class="text-sm">Label sistem: <strong>{{ $systemLabel }}</strong></p>
                <label for="label-override-final" class="mt-3 block font-medium">Label final</label>
                <select id="label-override-final" wire:model.live="labelOverrideFinal" class="mt-1">
                    <option value="">Sama dengan sistem ({{ $systemLabel }})</option>
                    @foreach (['DISARANKAN', 'DIPERTIMBANGKAN', 'TIDAK_DISARANKAN'] as $label)
                        @if ($label !== $systemLabel)
                            <option value="{{ $label }}">{{ $label }}</option>
                        @endif
                    @endforeach
                </select>
                @if ($labelOverrideFinal !== null && $labelOverrideFinal !== $systemLabel)
                    <label for="label-override-reason" class="mt-3 block font-medium">Alasan perubahan label (minimal 20 karakter)</label>
                    <textarea id="label-override-reason" wire:model="labelOverrideReason" rows="3" class="mt-1" aria-describedby="label-override-count"></textarea>
                    <p id="label-override-count" class="mt-1 text-sm">{{ mb_strlen(trim($labelOverrideReason)) }} karakter</p>
                    @error('labelOverrideReason')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @endif
            </fieldset>

            {{-- G7 section --}}
            <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700" id="g7-section" aria-labelledby="g7-heading">
                <h3 id="g7-heading" class="font-semibold">Resolusi G7 — sumber data per aspek</h3>
                <p class="mt-1 text-sm">Untuk aspek yang memerlukan review G7 (ditandai di tabel), tetapkan level final dan alasan.</p>
            </section>

            {{-- Narrative clusters --}}
            <fieldset class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <legend class="px-1 font-semibold">Narasi empat klaster</legend>
                <div class="mt-2 grid gap-4 lg:grid-cols-2">
                    @foreach (['A', 'B', 'C', 'D'] as $cluster)
                        <div id="cluster-{{ $cluster }}">
                            <label for="cluster-{{ $cluster }}-text" class="block font-medium">Klaster {{ $cluster }}</label>
                            <textarea
                                id="cluster-{{ $cluster }}-text"
                                wire:model="narrativeClusters.{{ $cluster }}"
                                rows="3"
                                class="mt-1"
                                aria-describedby="cluster-{{ $cluster }}-error"
                            ></textarea>
                            @error("narrativeClusters.$cluster")<p id="cluster-{{ $cluster }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
            </fieldset>

            {{-- Procedure note & accompaniment --}}
            <div class="grid gap-4 lg:grid-cols-2">
                <fieldset class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <legend class="px-1 font-semibold">Validitas dan prosedur</legend>
                    <label for="procedure-note" class="block font-medium">Catatan prosedur @if($validity === 'V2')(wajib untuk V2)@endif</label>
                    <textarea id="procedure-note" wire:model="procedureNote" rows="3" class="mt-1" aria-describedby="procedure-note-error"></textarea>
                    @error('procedureNote')<p id="procedure-note-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <legend class="px-1 font-semibold">Syarat pendampingan</legend>
                    <label for="accompaniment-conditions" class="block font-medium">Syarat pendampingan (wajib untuk DIPERTIMBANGKAN)</label>
                    <textarea id="accompaniment-conditions" wire:model="accompanimentConditions" rows="3" class="mt-1" aria-describedby="accompaniment-error"></textarea>
                    @error('accompanimentConditions')<p id="accompaniment-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
                </fieldset>
            </div>

            {{-- Validation button --}}
            <div class="flex gap-2">
                <button type="button" wire:click="validateDraft" wire:loading.attr="disabled" class="rounded-lg bg-primary-600 px-4 py-2 font-semibold text-white hover:bg-primary-500">
                    Validasi kesiapan
                </button>
            </div>
        @endif

        {{-- Signing readiness panel --}}
        <section aria-live="polite" class="rounded-xl border border-gray-300 p-4 dark:border-gray-700" aria-labelledby="readiness-heading">
            <h2 id="readiness-heading" class="font-semibold">
                @if ($isReadOnly)
                    Laporan sudah ditandatangani
                @elseif (empty($blockingCodes))
                    Siap ditandatangani
                @else
                    Belum dapat ditandatangani
                @endif
            </h2>

            @if (!empty($blockingCodes))
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($blockingCodes as $code)
                        <li>
                            <button
                                type="button"
                                wire:click="focusBlocker('{{ $code }}')"
                                class="rounded text-left text-sm underline decoration-dotted hover:no-underline"
                                data-blocker="{{ $code }}"
                            >
                                {{ $code }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @if ($isReadOnly)
                    <button type="button" disabled class="cursor-not-allowed rounded-lg border px-4 py-2 opacity-60">Tanda tangan sudah dibuat</button>
                @elseif (empty($blockingCodes))
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" class="rounded-lg bg-green-700 px-4 py-2 font-bold text-white hover:bg-green-800">
                        Tandatangani Laporan
                    </button>
                @else
                    <button type="button" disabled class="cursor-not-allowed rounded-lg border px-4 py-2 opacity-60">Tanda tangan belum tersedia</button>
                @endif
            </div>
        </section>
    </div>
</x-filament-panels::page>
