<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrphanJefesResource\Pages;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * OrphanJefesResource — Lista de jefes cuya supervisora está inactiva o fue eliminada (Fase 5).
 *
 * Solo visible para super_admin.
 * Read-only excepto acción "Reasignar a otra supervisora".
 * Bulk action: reasignar todos los jefes de una supervisora X a una Y.
 */
class OrphanJefesResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';
    protected static string|\UnitEnum|null $navigationGroup = 'Ramas huerfanas';
    protected static ?string $modelLabel = 'Jefe huerfano';
    protected static ?string $pluralModelLabel = 'Jefes huerfanos';
    protected static ?string $navigationLabel = 'Jefes';
    protected static ?int $navigationSort = 52;
    protected static ?string $slug = 'orphan-jefes';

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
    // Navigation badge — count de jefes huérfanos, rojo si > 0
    // =========================================================================

    public static function getNavigationBadge(): ?string
    {
        $count = User::orphan()->where('role', 'jefe')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // =========================================================================
    // Query base — solo jefes huérfanos
    // =========================================================================

    public static function getEloquentQuery(): Builder
    {
        return User::orphan()->where('role', 'jefe');
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
                    ->label('Jefe')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('parent.name')
                    ->label('Supervisora original')
                    ->placeholder('—')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state . ' (inactiva)')
                    ->tooltip(fn (User $record) => match ($record->orphanReason()) {
                        'parent_inactive' => 'La supervisora existe pero esta inactiva',
                        'parent_missing'  => 'La supervisora fue eliminada del sistema',
                        default           => 'Sin supervisora responsable',
                    }),

                TextColumn::make('children_count')
                    ->label('Creadores debajo')
                    ->counts('children')
                    ->badge()
                    ->color('info')
                    ->tooltip('Creadores directos de este jefe'),

                TextColumn::make('accounts_count')
                    ->label('Cuentas asignadas')
                    ->counts('accounts')
                    ->badge()
                    ->color('success')
                    ->tooltip('Cuentas que este jefe tiene asignadas'),

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
                    ->modalHeading(fn (User $record) => 'Reasignar jefe: ' . $record->name)
                    ->modalDescription('Selecciona la nueva supervisora activa. El jefe y sus creadores quedaran bajo su rama.')
                    ->schema([
                        Select::make('new_parent_id')
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
                    ->action(function (User $record, array $data): void {
                        $newParent = User::find($data['new_parent_id']);

                        if ($newParent === null) {
                            Notification::make()
                                ->title('Error: supervisora no encontrada')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $record->reassignParent($newParent);

                            Notification::make()
                                ->title('Jefe reasignado correctamente')
                                ->body("{$record->name} ahora esta bajo la supervision de {$newParent->name}.")
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
            ->bulkActions([
                BulkAction::make('reasignar_todos')
                    ->label('Reasignar seleccionados a supervisora...')
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reasignacion masiva de jefes')
                    ->modalDescription('Todos los jefes seleccionados seran movidos a la supervisora elegida.')
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
                    ->action(function (Collection $records, array $data): void {
                        $newSupervisor = User::find($data['new_supervisor_id']);

                        if ($newSupervisor === null) {
                            Notification::make()
                                ->title('Error: supervisora no encontrada')
                                ->danger()
                                ->send();

                            return;
                        }

                        $count = 0;
                        $errors = 0;

                        foreach ($records as $jefe) {
                            try {
                                $jefe->reassignParent($newSupervisor);
                                $count++;
                            } catch (\InvalidArgumentException $e) {
                                $errors++;
                            }
                        }

                        if ($count > 0) {
                            Notification::make()
                                ->title("Se reasignaron {$count} jefes correctamente")
                                ->body("Ahora estan bajo la supervision de {$newSupervisor->name}.")
                                ->success()
                                ->send();
                        }

                        if ($errors > 0) {
                            Notification::make()
                                ->title("{$errors} jefes no pudieron reasignarse")
                                ->body('Verifica que la supervisora seleccionada este activa y tenga el rol correcto.')
                                ->warning()
                                ->send();
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('No hay jefes huerfanos')
            ->emptyStateDescription('Todos los jefes activos tienen una supervisora activa asignada.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    // =========================================================================
    // Paginas
    // =========================================================================

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrphanJefes::route('/'),
        ];
    }
}
