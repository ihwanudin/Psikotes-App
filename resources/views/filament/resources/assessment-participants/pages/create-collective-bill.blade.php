<x-filament-panels::page>
    <div class="space-y-6">
        <p>Pilih attempt yang ditanggung cabang. Nominal dihitung server dan belum membuat tagihan sampai dikonfirmasi.</p>
        <fieldset class="min-w-0 space-y-3"><legend class="font-semibold">Attempt tersedia</legend>
            @foreach ($choices as $choice)
                <div class="space-y-2 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" class="fi-checkbox-input shrink-0" wire:model.live="selected"
                            value="{{ $choice['assessmentParticipantId'] }}" @disabled(! $choice['enabled'])>
                        <span class="min-w-0 break-words"><strong>{{ $choice['participantName'] ?? 'Attempt tidak tersedia' }}</strong>
                            <span class="block">ID attempt: {{ $choice['assessmentAttemptId'] ?? $choice['assessmentParticipantId'] }}</span>
                            @if (! $choice['enabled'])<span class="block">{{ $choice['disabledReason'] }}</span>@endif
                        </span>
                    </label>
                    @if ($choice['enabled'] && in_array($choice['assessmentParticipantId'], $selected, true))
                        <label class="flex items-center gap-3"><input type="checkbox" class="fi-checkbox-input shrink-0"
                            wire:model.live="consultation.{{ $choice['assessmentParticipantId'] }}"> Sertakan konsultasi</label>
                    @endif
                </div>
            @endforeach
        </fieldset>
        @error('selected')<p role="alert">{{ $message }}</p>@enderror
        <x-filament::button wire:click="review">Tinjau tagihan</x-filament::button>
        @if ($preview !== null)
            <section aria-live="polite" class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <h2 class="font-semibold">Konfirmasi tinjauan</h2>
                <p>{{ $preview['paidCount'] }} attempt · IDR {{ number_format($preview['totalAmount'], 0, ',', '.') }}</p>
                <label class="block">Metode pembayaran
                    <select wire:model="paymentMethodId" class="fi-select-input mt-1 block w-full">
                        <option value="">Pilih metode</option>@foreach ($paymentMethods as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <p>Konfirmasi akan mereservasi satu tagihan. Perubahan data meminta tinjauan ulang.</p>
                <x-filament::button wire:click="confirm" wire:loading.attr="disabled">Konfirmasi tagihan</x-filament::button>
            </section>
        @endif
    </div>
</x-filament-panels::page>
