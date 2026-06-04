<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrphanCreadoresResource\Pages;
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
 * OrphanCreadoresResource — Lista de creadores cuyo jefe está inactivo o fue eliminado (Fase 5).
 *
 * Solo visible para super_admin.
 * Read-only: canCreate/canEdit/canDelete = false.
 * Acción inline: "Reasignar a otro jefe" con modal Select de jefes activos.
 */
class OrphanCreadoresResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-minus';
    protected static string|\UnitEnum|null $navigationGroup = 'Ramas huerfanas';
    protected static ?string $modelLabel = 'Creador huerfano';
    protected static ?string $pluralModelLabel = 'Creadores huerfanos';
    protected static ?string $navigationLabel = 'Creadores';
    protected static ?int $navigationSort = 51;
    protected static ?string $slug = 'orphan-creadores';

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
    // Navigation badge — count de creadores huérfanos, rojo si > 0
    // =========================================================================

    public static function getNavigationBadge(): ?string
    {
        $count = User::orphan()->where('role', 'creador')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // =========================================================================
    // Query base — solo creadores huérfanos
    // =========================================================================

    public static function getEloquentQuery(): Builder
    {
        return User::orphan()->where('role', 'creador');
    }

    // =========================================================================
    // Formulario (requerido por Resource, no se usa al tener canCreate/Edit=false)
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
                    ->label('Creador')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('parent.name')
                    ->label('Jefe original')
                    ->placeholder('—')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state . ' (inactivo)')
                    ->tooltip(fn (User $record) => match ($record->orphanReason()) {
                        'parent_inactive' => 'El jefe existe pero esta inactivo',
                        'parent_missing'  => 'El jefe fue eliminado del sistema',
                        default           => 'Sin jefe responsable',
                    }),

                TextColumn::make('parent.parent.name')
                    ->label('Rama (supervisora)')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Alta')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('reasignar')
                    ->label('Reasignar a otro jefe')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->modalHeading(fn (User $record) => 'Reasignar creador: ' . $record->name)
                    ->modalDescription('Selecciona el nuevo jefe activo. El creador quedara bajo su supervision inmediatamente.')
                    ->schema([
                        Select::make('new_parent_id')
                            ->label('Nuevo jefe')
                            ->options(
                                User::where('role', 'jefe')
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => $u->name . ' (jefe)',
                                    ])
                                    ->all()
                            )
                            ->required()
                            ->searchable()
                            ->helperText('Solo se muestran jefes activos.'),
                    ])
                    ->action(function (User $record, array $data): void {
                        $newParent = User::find($data['new_parent_id']);

                        if ($newParent === null) {
                            Notification::make()
                                ->title('Error: jefe no encontrado')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $record->reassignParent($newParent);

                            Notification::make()
                                ->title('Creador reasignado correctamente')
                                ->body("{$record->name} ahora esta bajo {$newParent->name}.")
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
            ->emptyStateHeading('No hay creadores huerfanos')
            ->emptyStateDescription('Todos los creadores activos tienen un jefe activo asignado.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    // =========================================================================
    // Paginas
    // =========================================================================

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrphanCreadores::route('/'),
        ];
    }
}
