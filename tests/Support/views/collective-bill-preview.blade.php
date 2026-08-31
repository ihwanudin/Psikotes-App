<div class="space-y-6">
    <header class="space-y-2">
        <h1 class="text-xl font-semibold">Tinjauan kolektif — data sintetis</h1>
        <p>Pratinjau baca-saja. Belum membuat tagihan atau memberi akses tes.</p>
    </header>

    <form wire:submit="review" class="space-y-4">
        <fieldset class="space-y-3" wire:loading.attr="disabled">
            <legend class="font-semibold">Pilih attempt dan konsultasi</legend>
            <p id="selection-help">Pilih peserta secara eksplisit, lalu tekan Tinjau. Nominal berasal dari server.</p>
            @foreach ($choices as $choice)
                @php
                    $id = $choice['assessmentParticipantId'];
                    $selectedIndex = null;
                    foreach ($selection as $index => $selected) {
                        if (is_array($selected) && ($selected['assessmentParticipantId'] ?? null) === $id) {
                            $selectedIndex = $index;
                            break;
                        }
                    }
                @endphp
                <div wire:key="choice-{{ $id }}" class="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <label for="attempt-{{ $id }}" class="flex items-start gap-3">
                        <input id="attempt-{{ $id }}" type="checkbox" wire:click="toggleAttempt({{ $id }})"
                            @checked($selectedIndex !== null) aria-describedby="selection-help" class="fi-checkbox-input" />
                        <span class="min-w-0 break-words">
                            <span class="font-semibold">{{ $choice['participantName'] ?? 'Peserta tidak tersedia' }}</span>
                            <span class="block">ID pilihan: {{ $id }}</span>
                            @if ($choice['status'] !== 'unavailable')
                                <span class="block">{{ $choice['externalCandidateId'] }} · {{ $choice['assessmentAttemptId'] }}</span>
                                <span class="block">{{ $choice['period'] ?? 'Periode belum tersedia' }}</span>
                            @else
                                <span class="block">{{ $choice['reason'] }}</span>
                            @endif
                        </span>
                    </label>
                    @if ($selectedIndex !== null)
                        <label for="consultation-{{ $id }}" class="flex items-center gap-3">
                            <input id="consultation-{{ $id }}" type="checkbox"
                                wire:model.live="selection.{{ $selectedIndex }}.consultationRequested" class="fi-checkbox-input" />
                            <span>Konsultasi untuk ID pilihan {{ $id }}</span>
                        </label>
                    @endif
                </div>
            @endforeach
        </fieldset>

        @error('selection')
            <p role="alert">{{ $message }}</p>
        @enderror
        <x-filament::button type="submit" wire:loading.attr="disabled">Tinjau</x-filament::button>
        <p wire:loading role="status">Memeriksa pilihan pada server…</p>
    </form>

    {{-- Hide the old result while a choice/review request is in flight. Hydration
         also clears it server-side; no cached hash is treated as a current result. --}}
    <section wire:loading.remove aria-live="polite" class="space-y-4">
        @if ($preview !== null)
            <h2 class="text-lg font-semibold">Hasil tinjauan sementara</h2>
            <p>Berbiaya: {{ $preview['paidCount'] }} · Gratis: {{ $preview['freeCount'] }}</p>
            <p class="font-semibold">
                @if ($preview['totalAmount'] === null)
                    Total belum tersedia — periksa alasan setiap item.
                @else
                    Total: {{ $preview['currency'] }} {{ number_format($preview['totalAmount'], 0, ',', '.') }}
                @endif
            </p>
            <ul class="space-y-3">
                @foreach ($preview['items'] as $item)
                    <li wire:key="preview-{{ $item['assessmentParticipantId'] }}" class="space-y-1 break-words rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <h3 class="font-semibold">{{ $item['participantName'] ?? 'Peserta tidak tersedia' }} · ID pilihan {{ $item['assessmentParticipantId'] }}</h3>
                        @if ($item['status'] === 'unavailable')
                            <p>{{ $item['reason'] }}</p>
                        @else
                            <p>{{ $item['externalCandidateId'] }} · {{ $item['assessmentAttemptId'] }} · {{ $item['period'] ?? 'Periode belum tersedia' }}</p>
                            <p>{{ $item['packageCode'] }} · {{ $item['packageName'] }}</p>
                            <dl>
                                <div><dt class="inline">Paket:</dt> <dd class="inline">{{ $item['currency'] }} {{ number_format($item['baseAmount'], 0, ',', '.') }}</dd></div>
                                <div><dt class="inline">Konsultasi ({{ $item['consultationRequested'] ? 'dipilih' : 'tidak dipilih' }}):</dt> <dd class="inline">{{ $item['currency'] }} {{ number_format($item['consultationAmount'], 0, ',', '.') }}</dd></div>
                                <div><dt class="inline">Jumlah:</dt> <dd class="inline">{{ $item['currency'] }} {{ number_format($item['amount'], 0, ',', '.') }}</dd></div>
                            </dl>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p>Hasil ini bukan reservasi. Perubahan pilihan atau pemuatan ulang memerlukan tinjauan baru.</p>
        @else
            <p>Belum ada tinjauan untuk pilihan saat ini.</p>
        @endif
    </section>
</div>
