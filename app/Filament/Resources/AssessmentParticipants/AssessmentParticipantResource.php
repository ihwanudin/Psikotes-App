<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssessmentParticipants;

use App\Actions\Integrations\IssueAssessmentInvitation;
use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Filament\Resources\AssessmentParticipants\Pages\ListAssessmentParticipants;
use App\Models\Admin;
use App\Models\AssessmentParticipant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LogicException;
use UnitEnum;

final class AssessmentParticipantResource extends Resource
{
    protected static ?string $model = AssessmentParticipant::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Asesmen Organisasi';

    protected static ?string $navigationLabel = 'Peserta';

    protected static ?string $modelLabel = 'peserta asesmen';

    protected static ?string $pluralModelLabel = 'peserta asesmen';

    public static function canViewAny(): bool
    {
        $admin = Filament::auth()->user();

        return $admin instanceof Admin && $admin->canPerform(AdminAbility::ViewParticipants);
    }

    /** @return Builder<AssessmentParticipant> */
    public static function getEloquentQuery(): Builder
    {
        $query = AssessmentParticipant::query()->with(['participant:id,full_name', 'client:id,client_id']);
        $admin = Filament::auth()->user();

        if (! $admin instanceof Admin || ! $admin->canPerform(AdminAbility::ViewParticipants)) {
            return $query->whereRaw('1 = 0');
        }

        if (! in_array($admin->role, [AdminRole::SuperAdmin, AdminRole::Psychologist], true)) {
            $query->where('organization_id', $admin->branch_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('participant.full_name')->label('Peserta')->placeholder('Nama belum dilengkapi')->searchable()->sortable(),
                TextColumn::make('external_candidate_id')->label('ID Kandidat')->searchable(),
                TextColumn::make('assessment_round_id')->label('Periode')->searchable(),
                TextColumn::make('assessment_status')->label('Status')->badge()->sortable(),
                TextColumn::make('recommendation')->label('Rekomendasi')->badge()->placeholder('Belum final'),
                TextColumn::make('created_at')->label('Diprovisikan')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('assessment_status')->label('Status')->options(array_combine(
                    ['PROVISIONED', 'READY', 'IN_PROGRESS', 'COMPLETED', 'UNDER_REVIEW', 'FINALIZED', 'REVOKED', 'VOID'],
                    ['Provisioned', 'Ready', 'In progress', 'Completed', 'Under review', 'Finalized', 'Revoked', 'Void'],
                )),
                SelectFilter::make('package_id')->label('Paket')->relationship('package', 'name'),
            ])
            ->headerActions([
                Action::make('export')
                    ->label('Ekspor aman')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (): string => route('admin.assessment-participants.export'))
                    ->openUrlInNewTab(),
            ])
            ->recordActions([
                Action::make('invitation')
                    ->label('Buat / ulangi undangan')
                    ->icon('heroicon-o-link')
                    ->visible(fn (AssessmentParticipant $record): bool => in_array($record->assessment_status, ['READY', 'IN_PROGRESS'], true))
                    ->requiresConfirmation()
                    ->modalDescription('Tautan aktif sebelumnya akan dicabut. Tautan baru hanya ditampilkan sekali dan harus segera disalin.')
                    ->action(function (AssessmentParticipant $record): void {
                        $issued = app(IssueAssessmentInvitation::class)->handle(self::currentAdmin(), $record);

                        Notification::make()
                            ->title('Tautan undangan siap disalin')
                            ->body("Berlaku sampai {$issued->expiresAt->timezone(config('app.timezone'))->format('d M Y H:i')}: {$issued->url}")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordUrl(null)
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return ['index' => ListAssessmentParticipants::route('/')];
    }

    private static function currentAdmin(): Admin
    {
        $admin = Filament::auth()->user();

        if (! $admin instanceof Admin) {
            throw new LogicException('An authenticated administrator is required.');
        }

        return $admin;
    }
}
