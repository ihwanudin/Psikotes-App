<div>
    {{ $this->probeAction() }}

    @if ($submitted)
        <p role="status">Aksi minimal tersimpan.</p>
    @endif

    <x-filament-actions::modals />
</div>
