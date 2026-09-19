<x-filament-panels::page>
    <style>
        .report-generation { display: grid; gap: 1.5rem; min-width: 0; }
        .report-generation section { border: 1px solid #d1d5db; border-radius: .75rem; background: #fff; padding: 1rem; }
        .report-generation button { min-height: 2.75rem; border: 1px solid #9ca3af; border-radius: .5rem; padding: .5rem 1rem; }
        .report-generation button:hover { background: #f3f4f6; }
        .report-generation button:focus-visible,
        .report-generation a:focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
        .report-generation [role="alert"] { border-color: #f59e0b; background: #fffbeb; color: #78350f; }
        .report-generation [data-gap] { font-family: monospace; font-size: .75rem; }
        .dark .report-generation section { border-color: #4b5563; background: #111827; color: #f9fafb; }
    </style>

    <div class="report-generation">
        <header>
            <p class="text-sm text-gray-600 dark:text-gray-300">Kasus {{ $casePublicId }}</p>
            @if ($snapshotId !== null)
                <p class="text-sm text-gray-600 dark:text-gray-300">Snapshot tanda tangan {{ $snapshotId }}</p>
            @endif
        </header>

        @if ($ready)
            <section aria-labelledby="generate-heading">
                <h2 id="generate-heading" class="text-lg font-semibold">Data laporan lengkap</h2>
                <p class="mt-1">PDF dibuat dari snapshot bertanda tangan terbaru dan disimpan privat. Tautan unduhan berlaku {{ $urlMinutes }} menit.</p>
                <button type="button" class="mt-3" wire:click="generate" wire:loading.attr="disabled">Buat PDF HPP</button>

                @if ($published !== null)
                    <p class="mt-3">
                        <a href="{{ $published['url'] }}" target="_blank" rel="noopener noreferrer" class="underline">Unduh PDF HPP</a>
                        <span class="text-sm text-gray-600 dark:text-gray-300">(berlaku hingga {{ $published['expires_at'] }})</span>
                    </p>
                @endif
            </section>
        @else
            <section role="alert" aria-labelledby="gaps-heading">
                <h2 id="gaps-heading" class="text-lg font-semibold">PDF belum dapat dibuat</h2>
                <p class="mt-1">Data berikut belum tersedia. Tidak ada nilai yang diisi otomatis.</p>
                <ul class="mt-2 list-disc pl-5">
                    @foreach ($gaps as $gap)
                        <li>{{ $gap['label'] }} <span data-gap>{{ $gap['code'] }}</span></li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
