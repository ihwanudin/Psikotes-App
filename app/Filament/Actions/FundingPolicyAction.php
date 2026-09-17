<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Payments\UpdateFundingPolicy;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Policies\FundingPolicyPolicy;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FundingPolicyAction
{
    private const array OPTIONS = ['self' => 'Bayar sendiri', 'organization' => 'Dibayar lembaga'];

    public static function make(): Action
    {
        return Action::make('fundingPolicy')
            ->label('Atur pembayar')->icon('heroicon-o-adjustments-horizontal')
            ->authorize(fn (): bool => self::authorized())
            ->modalHeading(fn (IntegrationClient|IntegrationSource $record): string => $record instanceof IntegrationClient
                ? 'Pembayar lembaga — '.$record->organization->display_name
                : 'Pembayar sumber — '.$record->source_system)
            ->modalDescription(function (IntegrationClient|IntegrationSource $record): string {
                $target = self::target($record);
                $unconfigured = $target->allowed_payer_types === null ? 'Belum dikonfigurasi. Simpan untuk menetapkan pilihan. ' : '';

                return $unconfigured.($record instanceof IntegrationClient
                    ? 'Berlaku untuk semua klien dan sumber lembaga ini. Sumber tetap perlu mengizinkan pilihan yang sama.'
                    : 'Pilihan efektif adalah irisan izin sumber dan lembaga. Mengaktifkan sumber tidak menambah izin lembaga.');
            })
            ->modalSubmitActionLabel('Simpan pengaturan')->modalCancelActionLabel('Batal')
            ->closeModalByClickingAway(false)
            ->beforeFormValidated(function (Action $action): void {
                // Validate raw input before option casts can discard malformed entries.
                $path = 'mountedActions.'.$action->getNestingIndex().'.data';
                $raw = data_get($action->getLivewire(), $path);
                try {
                    Validator::make(is_array($raw) ? $raw : [], [
                        'allowed_payer_types' => ['present', 'array', 'list', 'max:2'],
                        'allowed_payer_types.*' => ['required', 'string', Rule::in(array_keys(self::OPTIONS))],
                    ])->validate();
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        $path.'.allowed_payer_types' => 'Pilih hanya Bayar sendiri atau Dibayar lembaga. Input tidak valid; muat ulang form dan coba lagi.',
                    ]);
                }
            })
            ->fillForm(function (IntegrationClient|IntegrationSource $record): array {
                $target = self::target($record);
                $data = ['allowed_payer_types' => $target->allowed_payer_types ?? []];
                if ($target instanceof IntegrationSource) {
                    $data['locked_payer_type'] = $target->locked_payer_type;
                }

                return $data;
            })
            ->schema(fn (IntegrationClient|IntegrationSource $record): array => [
                CheckboxList::make('allowed_payer_types')->label('Pembayar yang diizinkan')
                    ->options(self::OPTIONS)->live()->rules(['array', 'list', 'max:2'])
                    ->helperText('Centang untuk ON, kosongkan untuk OFF. Jika semua OFF, checkout baru ditolak. Tagihan lama tidak berubah.'),
                ...($record instanceof IntegrationSource ? [
                    Select::make('locked_payer_type')->label('Pembayar terkunci')
                        ->options(fn (Get $get): array => array_intersect_key(self::OPTIONS, array_flip($get('allowed_payer_types') ?? [])))
                        ->placeholder('Tidak dikunci')->nullable()
                        ->validationMessages(['in' => 'Pembayar terkunci harus diizinkan. Aktifkan kembali pilihannya, lalu hapus kunci sebelum mematikannya.'])
                        ->helperText('Jika dikunci, peserta tidak dapat mengganti pembayar. Untuk mematikan semua pilihan, hapus kunci terlebih dahulu.'),
                ] : []),
            ])
            ->action(function (IntegrationClient|IntegrationSource $record, array $data, Schema $schema): void {
                $admin = Filament::auth()->user();
                if (! $admin instanceof Admin) {
                    throw new AuthorizationException;
                }
                // Never infer scope from editable form fields or action arguments.
                $target = self::target($record);
                try {
                    $update = app(UpdateFundingPolicy::class);
                    if ($target instanceof Branch) {
                        $update->forOrganization($admin, $target->id, $data);
                    } else {
                        $update->forSource($admin, $target->client->organization_id, $target->id, $data);
                    }
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$schema->getStatePath().'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                Notification::make()->success()->title('Pengaturan pembayar tersimpan')
                    ->body('Berlaku untuk checkout baru. Tagihan dan akses tes yang sudah ada tidak berubah.')->send();
            });
    }

    private static function authorized(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin && app(FundingPolicyPolicy::class)->update($admin);
    }

    private static function target(IntegrationClient|IntegrationSource $record): Branch|IntegrationSource
    {
        if ($record instanceof IntegrationClient) {
            return IntegrationClient::query()->findOrFail($record->id)->organization;
        }

        return IntegrationSource::query()->with('client')->findOrFail($record->id);
    }
}
