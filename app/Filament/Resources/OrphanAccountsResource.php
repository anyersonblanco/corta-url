<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrphanAccountsResource\Pages;
use App\Models\Account;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * OrphanAccountsResource — Lista de cuentas cuya supervisora está inactiva o fue eliminada (Fase 5).
 *
 * Solo visible para super_admin.
 * Read-only excepto acción "Reasignar a otra supervisora".
 */
class OrphanAccountsResource extends Resource
{
    protected static ?string $model = Account::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';
    protected static string|\UnitEnum|null $navigationGroup = 'Ramas huerfanas';
    protected static ?string $modelLabel = 'Cuenta huerfana';
    protected static ?string $pluralModelLabel = 'Cuentas huerfanas';
    protected static ?string $navigationLabel = 'Cuentas';
    protected static ?int $navigationSort = 53;
    protected static ?string $slug = 'orphan-accounts';

    // =========================================================================
    // Autorización — solo super_admin
    // =========================================================================

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user !== null && $user->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    // =========================================================================
    // Navigation badge — count de cuentas huérfanas, rojo si > 0
    // =========================================================================

    public static function getNavigationBadge(): ?string
    {
        $count = Account::orphan()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // =========================================================================
    // Query base — solo cuentas huérfanas
    // =========================================================================

    public static function getEloquentQuery(): Builder
    {
        return Account::orphan();
    }

    // =========================================================================
    // Formulario (requerido por Resource, no se usa)
    // =========================================================================

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    // =========================================================================
    // Tabla
    // =========================================================================

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Cuenta / Cliente')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('supervisor.name')
                    ->label('Supervisora original')
                    ->placeholder('—')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state . ' (inactiva)')
                    ->tooltip(fn (Account $record) => match ($record->orphanReason()) {
                        'supervisor_inactive' => 'La supervisora existe pero esta inactiva',
                        'supervisor_missing'  => 'La supervisora fue eliminada del sistema',
                        default               => 'Sin supervisora responsable',
                    }),

                TextColumn::make('users_count')
                    ->label('Usuarios asignados')
                    ->counts('users')
                    ->badge()
                    ->color('info')
                    ->tooltip('Jefes y creadores con acceso a esta cuenta'),

                TextColumn::make('links_count')
                    ->label('Links')
                    ->counts('links')
                    ->badge()
                    ->color('success')
                    ->tooltip('Links asociados a esta cuenta'),

                TextColumn::make('created_at')
                    ->label('Alta')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('reasignar')
                    ->label('Reasignar a otra supervisora')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->modalHeading(fn (Account $record) => 'Reasignar cuenta: ' . $record->name)
                    ->modalDescription('Selecciona la nueva supervisora activa que gestionara esta cuenta.')
                    ->schema([
                        Select::make('new_supervisor_id')
                            ->label('Nueva supervisora')
                            ->options(
                                User::where('role', 'supervisor')
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => $u->name . ' (supervisora)',
                                    ])
                                    ->all()
                            )
                            ->required()
                            ->searchable()
                            ->helperText('Solo se muestran supervisoras activas.'),
                    ])
                    ->action(function (Account $record, array $data): void {
                        $newSupervisor = User::find($data['new_supervisor_id']);

                        if ($newSupervisor === null) {
                            Notification::make()
                                ->title('Error: supervisora no encontrada')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $record->reassignSupervisor($newSupervisor);

                            Notification::make()
                                ->title('Cuenta reasignada correctamente')
                                ->body("La cuenta '{$record->name}' ahora esta bajo {$newSupervisor->name}.")
                                ->success()
                                ->send();
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('No se pudo reasignar')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->emptyStateHeading('No hay cuentas huerfanas')
            ->emptyStateDescription('Todas las cuentas activas tienen una supervisora activa asignada.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    // =========================================================================
    // Paginas
    // =========================================================================

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrphanAccounts::route('/'),
        ];
    }
}
