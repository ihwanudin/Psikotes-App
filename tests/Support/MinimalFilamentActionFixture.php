<?php

declare(strict_types=1);

namespace Tests\Support;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class MinimalFilamentActionFixture extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public bool $submitted = false;

    public function probeAction(): Action
    {
        return Action::make('probe')
            ->label('Buka modal minimal')
            ->schema([
                TextInput::make('synthetic')->label('Nilai sintetis')->required(),
                FileUpload::make('synthetic_file')->label('Berkas sintetis'),
            ])
            ->action(function (): void {
                $this->submitted = true;
            });
    }

    public function render(): View
    {
        return view()->file(__DIR__.'/views/minimal-filament-action.blade.php');
    }
}
