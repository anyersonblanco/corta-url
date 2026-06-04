<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * UserResource — Gestión de usuarios y jerarquía de roles (Fase 1).
 *
 * Visibilidad por rol:
 *  - super_admin   : ve todos los usuarios, puede crear cualquier rol
 *  - supervisor    : ve solo su rama (jefes y creadores creados por sus jefes)
 *  - jefe          : ve solo sus creadores directos
 *  - creador       : NO puede acceder (canViewAny = false)
 *
 * Scoping en getEloquentQuery() — Fase 3 completa el filtrado por rama.
 * En Fase 1 el scope aplica la visibilidad básica para UserResource.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static string|\UnitEnum|null $navigationGroup = 'Administración';
    protected static ?string $modelLabel = 'Usuario';
    protected static ?string $pluralModelLabel = 'Usuarios';
    protected static ?string $navigationLabel = 'Usuarios';
    protected static ?int $navigationSort = 10;

    // =========================================================================
    // Visibilidad del recurso por rol
    // =========================================================================

    /**
     * Creadores no pueden ver ni acceder a este recurso.
     */
    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        // El Gate::before ya bypass para super_admin, pero canViewAny no pasa por Gate
        // en Filament Resources si se sobreescribe manualmente — así que lo chequeamos explícito.
        return ! $user->isCreador();
    }

    // =========================================================================
    // Scoping por rama (Fase 1 básico — Fase 3 completa la cascada)
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
            // Ve: sus jefes directos (parent_id = $user->id)
            // y los creadores de esos jefes (parent_id IN <ids de sus jefes>)
            $jefeIds = User::where('parent_id', $user->id)->pluck('id');

            return $base->where(function (Builder $q) use ($user, $jefeIds) {
                $q->where('parent_id', $user->id)                    // jefes directos
                  ->orWhereIn('parent_id', $jefeIds);                 // creadores de sus jefes
            });
        }

        if ($user->isJefe()) {
            // Ve solo sus creadores directos
            return $base->where('parent_id', $user->id);
        }

        // Creador: sin acceso (canViewAny = false ya lo bloquea antes de llegar aquí)
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
            Section::make('Datos del usuario')
                ->description('Información básica de acceso al panel.')
                ->components([
                    TextInput::make('name')
                        ->label('Nombre completo')
                        ->placeholder('Ej: María García')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Correo electrónico (@webtilia.com)')
                        ->placeholder('usuario@webtilia.com')
                        ->helperText('Solo se permiten correos @webtilia.com para acceder al panel.')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Ya existe un usuario con ese correo electrónico.',
                        ])
                        ->rules([
                            fn () => function (string $attribute, $value, \Closure $fail) {
                                if (! str_ends_with(strtolower($value ?? ''), '@webtilia.com')) {
                                    $fail('El correo debe terminar en @webtilia.com.');
                                }
                            },
                        ]),

                    TextInput::make('password')
                        ->label('Contraseña')
                        ->password()
                        ->revealable()
                        ->helperText('Mínimo 8 caracteres. Dejá vacío al editar para no cambiarla.')
                        ->minLength(8)
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn ($state) => filled($state))
                        ->required(fn (string $operation) => $operation === 'create'),
                ])
                ->columns(1),

            Section::make('Rol y jerarquía')
                ->description('Define qué puede hacer este usuario y quién lo supervisa.')
                ->components([
                    Select::make('role')
                        ->label('Rol')
                        ->required()
                        ->options(function () use ($authUser): array {
                            if ($authUser === null) {
                                return [];
                            }

                            $labels = [
                                'super_admin' => 'Super administrador',
                                'supervisor'  => 'Supervisora',
                                'jefe'        => 'Jefe',
                                'creador'     => 'Creador',
                            ];

                            $allowed = $authUser->creatableRoles();

                            return array_intersect_key($labels, array_flip($allowed));
                        })
                        ->helperText(function () use ($authUser): string {
                            if ($authUser === null) {
                                return '';
                            }

                            return match ($authUser->role) {
                                'super_admin' => 'Como super administrador podés asignar cualquier rol.',
                                'supervisor'  => 'Podés crear únicamente usuarios con rol Jefe.',
                                'jefe'        => 'Podés crear únicamente usuarios con rol Creador.',
                                default       => '',
                            };
                        })
                        ->live(), // reactive para que parent_id se filtre cuando cambia el rol

                    Select::make('parent_id')
                        ->label('Creado por (responsable directo)')
                        ->helperText('Quién en la jerarquía es responsable de este usuario. Se autoasigna a vos al crear (super_admin puede cambiarlo).')
                        ->options(function () use ($authUser): array {
                            if ($authUser === null) {
                                return [];
                            }

                            // super_admin puede elegir libremente entre todos los usuarios activos
                            // que sean padres válidos (no creadores, no el propio usuario).
                            if ($authUser->isSuperAdmin()) {
                                return User::where('is_active', true)
                                    ->whereIn('role', ['super_admin', 'supervisor', 'jefe'])
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (User $u) => [
                                        $u->id => $u->name . ' (' . $u->role . ')',
                                    ])
                                    ->all();
                            }

                            // Para supervisor/jefe: solo pueden asignarse a sí mismos como padre
                            // (el parent_id se setea automáticamente al autenticado)
                            return [
                                $authUser->id => $authUser->name . ' (vos mismo)',
                            ];
                        })
                        ->default(fn () => $authUser?->id)
                        ->hidden(fn () => $authUser !== null && ! $authUser->isSuperAdmin())
                        ->nullable()
                        ->searchable(),
                ])
                ->columns(1),

            Section::make('Estado de la cuenta')
                ->collapsible()
                ->components([
                    Toggle::make('is_active')
                        ->label('Usuario activo')
                        ->helperText('Si se desactiva, el usuario no puede ingresar al panel. Sus datos, enlaces y cuentas asignadas se conservan intactos.')
                        ->default(true),
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
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Correo copiado'),

                TextColumn::make('role')
                    ->label('Rol')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'super_admin' => 'Super Admin',
                        'supervisor'  => 'Supervisora',
                        'jefe'        => 'Jefe',
                        'creador'     => 'Creador',
                        default       => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'super_admin' => 'danger',
                        'supervisor'  => 'warning',
                        'jefe'        => 'info',
                        'creador'     => 'success',
                        default       => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('parent.name')
                    ->label('Creado por')
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->tooltip('Si el usuario puede ingresar al panel'),

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

                // Acción inline para activar/desactivar sin entrar al edit
                \Filament\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'Desactivar' : 'Activar')
                    ->tooltip(fn (User $record) => $record->is_active
                        ? 'Desactiva este usuario. Sus datos se conservan.'
                        : 'Reactiva este usuario.'
                    )
                    ->icon(fn (User $record) => $record->is_active
                        ? 'heroicon-o-no-symbol'
                        : 'heroicon-o-check-circle'
                    )
                    ->color(fn (User $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => $record->is_active
                        ? 'Desactivar usuario: ' . $record->name
                        : 'Activar usuario: ' . $record->name
                    )
                    ->modalDescription(fn (User $record) => $record->is_active
                        ? 'El usuario no podrá ingresar al panel. Sus datos, enlaces y cuentas asignadas se conservan intactos. Podés reactivarlo cuando quieras.'
                        : 'El usuario podrá volver a ingresar al panel con sus datos anteriores.'
                    )
                    ->action(function (User $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                    })
                    ->hidden(fn (User $record): bool => $record->id === Auth::id()),
            ]);
    }

    // =========================================================================
    // Páginas
    // =========================================================================

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
            'view'   => Pages\ViewUser::route('/{record}'),
        ];
    }
}
