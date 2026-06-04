<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Models\Account;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * AccountResource — Gestión de cuentas de clientes Webtilia (Fase 2).
 *
 * Visibilidad y permisos por rol:
 *  - super_admin   : ve todas las cuentas, puede crear/editar/ver cualquiera.
 *  - supervisor    : ve solo sus cuentas (supervisor_id = auth user), puede crear y editar las suyas.
 *  - jefe          : ve solo las cuentas que tiene asignadas via pivot. NO puede crear.
 *  - creador       : sin acceso (canViewAny = false).
 *
 * La sección "Asignaciones" en el form permite a la supervisora asignar la cuenta
 * a sus jefes, y al jefe asignar (desde Edit) a sus creadores — solo cuentas que él ya tiene.
 */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';
    protected static string|\UnitEnum|null $navigationGroup = 'Administración';
    protected static ?string $modelLabel = 'Cuenta';
    protected static ?string $pluralModelLabel = 'Cuentas';
    protected static ?string $navigationLabel = 'Cuentas';
    protected static ?int $navigationSort = 20;

    // =========================================================================
    // Autorización
    // =========================================================================

    /**
     * Creadores no pueden acceder al resource de cuentas.
     */
    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return ! $user->isCreador();
    }

    /**
     * Solo super_admin (bypass Gate) y supervisoras pueden crear cuentas.
     */
    public static function canCreate(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return $user->isSuperAdmin() || $user->isSupervisor();
    }

    /**
     * super_admin (bypass) y supervisora dueña pueden editar.
     */
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->isSupervisor()) {
            return $record->supervisor_id === $user->id;
        }

        return false;
    }

    // =========================================================================
    // Scoping por rol
    // =========================================================================

    public static function getEloquentQuery(): Builder
    {
        $base = parent::getEloquentQuery();

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || $user->isSuperAdmin()) {
            return $base;
        }

        if ($user->isSupervisor()) {
            return $base->where('supervisor_id', $user->id);
        }

        if ($user->isJefe()) {
            return $base->whereHas('users', function (Builder $q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

        // creador: canViewAny=false bloquea antes, pero por defensa:
        return $base->whereRaw('1 = 0');
    }

    // =========================================================================
    // Formulario
    // =========================================================================

    public static function form(Schema $schema): Schema
    {
        /** @var User|null $authUser */
        $authUser = Auth::user();

        return $schema->components([
            Section::make('Datos de la cuenta')
                ->description('Información básica del cliente.')
                ->components([
                    TextInput::make('name')
                        ->label('Nombre de la cuenta / cliente')
                        ->placeholder('Ej: Dulanto Oftalmólogos')
                        ->required()
                        ->maxLength(255),

                    Select::make('supervisor_id')
                        ->label('Supervisora responsable')
                        ->helperText('La supervisora que gestiona esta cuenta. Se autoasigna a vos si sos supervisora.')
                        ->options(function () use ($authUser): array {
                            if ($authUser === null) {
                                return [];
                            }
                            // super_admin puede elegir entre todas las supervisoras activas
                            if ($authUser->isSuperAdmin()) {
                                return User::where('is_active', true)
                                    ->where('role', 'supervisor')
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            }
                            // supervisora: solo ella misma
                            return [
                                $authUser->id => $authUser->name . ' (vos misma)',
                            ];
                        })
                        ->default(fn () => $authUser?->isSupervisor() ? $authUser->id : null)
                        ->disabled(fn (string $operation) => $operation === 'edit' && $authUser !== null && ! $authUser->isSuperAdmin())
                        ->dehydrated(fn (string $operation) => $operation !== 'edit' || $authUser === null || $authUser->isSuperAdmin())
                        ->required()
                        ->searchable(),

                    Toggle::make('is_active')
                        ->label('Cuenta activa')
                        ->helperText('Si se desactiva, los usuarios no verán esta cuenta disponible para asignar a nuevos links.')
                        ->default(true),

                    Textarea::make('notes')
                        ->label('Notas internas')
                        ->placeholder('Información interna sobre el cliente: persona de contacto, particularidades, etc.')
                        ->helperText('Solo visible para el equipo en el panel. No se muestra al cliente.')
                        ->rows(3)
                        ->nullable(),
                ])
                ->columns(1),

            Section::make('Asignaciones')
                ->description(function () use ($authUser): string {
                    if ($authUser === null) {
                        return '';
                    }
                    if ($authUser->isSuperAdmin() || $authUser->isSupervisor()) {
                        return 'Asigná esta cuenta a los jefes que la gestionarán.';
                    }
                    if ($authUser->isJefe()) {
                        return 'Asigná esta cuenta a tus creadores para que puedan usarla.';
                    }
                    return '';
                })
                ->visible(fn (string $operation) => $operation === 'edit')
                ->components([
                    Select::make('assigned_users')
                        ->label('Usuarios con acceso a esta cuenta')
                        ->helperText('Los usuarios seleccionados podrán ver y usar esta cuenta al crear links.')
                        ->multiple()
                        ->options(function () use ($authUser): array {
                            if ($authUser === null) {
                                return [];
                            }

                            if ($authUser->isSuperAdmin()) {
                                // super_admin asigna a cualquier jefe o creador activo
                                return User::where('is_active', true)
                                    ->whereIn('role', ['jefe', 'creador'])
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => $u->name . ' (' . $u->role . ')',
                                    ])
                                    ->all();
                            }

                            if ($authUser->isSupervisor()) {
                                // supervisora asigna a sus jefes
                                return User::where('is_active', true)
                                    ->where('role', 'jefe')
                                    ->where('parent_id', $authUser->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            }

                            if ($authUser->isJefe()) {
                                // jefe asigna a sus creadores
                                return User::where('is_active', true)
                                    ->where('role', 'creador')
                                    ->where('parent_id', $authUser->id)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            }

                            return [];
                        })
                        ->relationship('users', 'name')
                        ->preload()
                        ->searchable(),
                ])
                ->columns(1),
        ]);
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
                    ->label('Supervisora')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('users_count')
                    ->label('Usuarios')
                    ->counts('users')
                    ->badge()
                    ->color('info')
                    ->tooltip('Cantidad de jefes y creadores con acceso a esta cuenta'),

                TextColumn::make('links_count')
                    ->label('Links')
                    ->counts('links')
                    ->badge()
                    ->color('success')
                    ->tooltip('Links asociados a esta cuenta'),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean()
                    ->tooltip('Si la cuenta está activa'),

                TextColumn::make('created_at')
                    ->label('Alta')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Ver'),
                EditAction::make()->label('Editar'),
            ]);
    }

    // =========================================================================
    // Mutación de datos (autoset supervisor_id para supervisoras)
    // =========================================================================

    public static function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var User|null $authUser */
        $authUser = Auth::user();

        // Si es supervisora, forzar supervisor_id al auth user (defensa extra)
        if ($authUser !== null && $authUser->isSupervisor()) {
            $data['supervisor_id'] = $authUser->id;
        }

        return $data;
    }

    // =========================================================================
    // Páginas
    // =========================================================================

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit'   => Pages\EditAccount::route('/{record}/edit'),
            'view'   => Pages\ViewAccount::route('/{record}'),
        ];
    }
}
