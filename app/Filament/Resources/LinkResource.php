<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LinkResource\Pages;
use App\Models\Account;
use App\Models\Link;
use App\Services\ShortLinkService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LinkResource extends Resource
{
    protected static ?string $model = Link::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';
    protected static string|\UnitEnum|null $navigationGroup = 'Acortador de enlaces';
    protected static ?string $modelLabel = 'Enlace acortado';
    protected static ?string $pluralModelLabel = 'Enlaces acortados';
    protected static ?string $navigationLabel = 'Enlaces';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Enlace a acortar')
                ->description('Pegá la URL larga que querés convertir en un enlace corto. Opcionalmente personalizá el slug (la parte final del enlace).')
                ->components([
                    TextInput::make('destination_url')
                        ->label('URL de destino (la URL larga)')
                        ->placeholder('https://www.misitio.com/landing-page-muy-larga')
                        ->helperText('Tiene que empezar con http:// o https://. Es la URL real a la que se redirige cuando alguien hace click en el enlace corto.')
                        ->required()
                        ->url()
                        ->maxLength(2048),
                    TextInput::make('slug')
                        ->label('Slug personalizado (opcional)')
                        ->placeholder('Ej: promo-mayo, evento-2026 (dejá vacío para generar uno aleatorio)')
                        ->helperText('La parte final del enlace corto. Solo letras, números, guiones (-) y guiones bajos (_). Entre 2 y 64 caracteres. Si lo dejás vacío, el sistema genera uno aleatorio de 7 caracteres.')
                        ->maxLength(64)
                        // Wrapper de closure obligatorio en Filament 4: si pasamos directo
                        // function($attribute,$value,$fail) Filament intenta resolver $attribute
                        // como dependency suya (Get/Set/component) y tira BindingResolutionException.
                        // El fn() externo se evalúa primero por Filament (sin args), retorna la
                        // closure Laravel-friendly que la validación corre normal.
                        //
                        // Uniqueness se valida con ->unique(ignoreRecord: true) nativo de Filament
                        // porque en /livewire/update request()->route('record') resuelve null y
                        // disparaba falsos positivos al editar.
                        ->rules([
                            fn () => function (string $attribute, $value, \Closure $fail) {
                                if (empty($value)) return; // permitido (se autogenera)
                                if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $value)) {
                                    $fail('El slug debe tener entre 2 y 64 caracteres y solo puede contener letras, números, guiones y guiones bajos.');
                                    return;
                                }
                                if (in_array(strtolower($value), ShortLinkService::RESERVED_SLUGS, true)) {
                                    $fail('Ese slug está reservado por el sistema (colisiona con una ruta). Probá otro.');
                                }
                            },
                        ])
                        ->unique(ignoreRecord: true)
                        ->validationMessages([
                            'unique' => 'Ese slug ya está en uso por otro enlace.',
                        ]),
                    TextInput::make('title')
                        ->label('Título o descripción interna')
                        ->placeholder('Ej: Campaña Día de la Madre 2026')
                        ->helperText('Cómo identificás este enlace internamente. NO se muestra al usuario que hace click — es solo para que vos lo reconozcas en el panel.')
                        ->maxLength(255),
                    TextInput::make('tags')
                        ->label('Etiquetas (opcional)')
                        ->placeholder('Ej: instagram, campaña-mayo, newsletter')
                        ->helperText('Lista separada por comas. Sirve para filtrar enlaces por canal o proyecto.')
                        ->maxLength(255),

                    // Fase 3 — Cuenta del cliente a la que pertenece este enlace.
                    // Pool restringido según rol del usuario autenticado:
                    //   super_admin → todas las cuentas
                    //   supervisor  → cuentas que él creó (createdAccounts)
                    //   jefe        → cuentas que tiene asignadas (pivot account_user)
                    //   creador     → cuentas que tiene asignadas (pivot account_user)
                    // Requerido SOLO para creadores; los demás roles pueden dejarlo null.
                    Select::make('account_id')
                        ->label('Cuenta del cliente')
                        ->helperText('A qué cliente pertenece este enlace. Los creadores deben elegir obligatoriamente.')
                        ->placeholder('— Sin asignar —')
                        ->options(function () {
                            $user = Auth::user();
                            if (!$user) {
                                return [];
                            }
                            $accounts = $user->assignableAccounts();
                            return $accounts->pluck('name', 'id')->all();
                        })
                        ->required(fn () => Auth::user()?->isCreador() ?? false)
                        ->searchable(),
                ])
                ->columns(1),

            Section::make('Configuración avanzada')
                ->description('Opcionales. Si no las cambiás, el enlace queda activo sin expiración.')
                ->collapsible()
                ->components([
                    Toggle::make('is_active')
                        ->label('Enlace activo')
                        ->helperText('Si está desactivado, los clicks ven una página "Enlace no disponible" en vez de redirigir.')
                        ->default(true)
                        ->columnSpan(1),
                    DateTimePicker::make('expires_at')
                        ->label('Fecha de expiración (opcional)')
                        ->helperText('Después de esta fecha el enlace deja de redirigir. Dejá vacío para que no expire nunca.')
                        ->seconds(false)
                        ->columnSpan(1),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slug')
                    ->label('Código corto')
                    ->copyable()
                    ->copyMessage('Slug copiado')
                    ->tooltip('Hacé click para copiar el slug. Es la parte final del enlace corto.')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('short_url_display')
                    ->label('Enlace corto completo')
                    ->state(fn (Link $r) => $r->shortUrl())
                    ->copyable()
                    ->copyMessage('Enlace corto copiado al portapapeles')
                    ->tooltip('Click para copiar el enlace corto completo. Esto es lo que se comparte.')
                    ->limit(40)
                    ->color('primary'),

                TextColumn::make('title')
                    ->label('Descripción')
                    ->tooltip(fn (Link $r) => $r->title)
                    ->limit(28)
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('destination_url')
                    ->label('Destino')
                    ->limit(30)
                    ->tooltip(fn (Link $r) => $r->destination_url)
                    ->url(fn (Link $r) => $r->destination_url, true)
                    ->color('gray'),

                TextColumn::make('clicks_count')
                    ->label('Clicks')
                    ->tooltip('Cantidad total de clicks que recibió el enlace corto')
                    ->alignCenter()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : ($state < 10 ? 'warning' : 'success')),

                TextColumn::make('tags')
                    ->label('Etiquetas')
                    ->badge()
                    ->separator(',')
                    ->color('info')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->tooltip('Si está activo, redirige cuando alguien hace click. Si no, muestra "Enlace no disponible".')
                    ->boolean(),

                TextColumn::make('expires_at')
                    ->label('Expira')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('No expira')
                    ->toggleable()
                    ->color(fn (?string $state) => $state && now()->gt($state) ? 'danger' : null),

                TextColumn::make('creator.name')
                    ->label('Creado por')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Fecha de creación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Filtrar por estado'),
            ])
            ->recordActions([
                Action::make('abrirCorto')
                    ->label('Abrir')
                    ->tooltip('Abrir el enlace corto en una pestaña nueva (cuenta como un click)')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (Link $r) => $r->shortUrl(), true),

                Action::make('verQR')
                    ->label('QR')
                    ->tooltip('Ver y descargar el código QR del enlace corto')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->modalHeading(fn (Link $r) => 'Código QR · ' . $r->slug)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar')
                    ->modalContent(fn (Link $r) => view('filament.wlink.qr-modal', [
                        'link' => $r,
                    ])),

                ViewAction::make()->label('Detalles'),
                EditAction::make()->label('Editar'),
                DeleteAction::make()->label('Eliminar'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()->label('Eliminar seleccionados'),
            ]);
    }

    /**
     * Garantizar que cuando se crea un Link sin slug, se autogenere uno antes
     * de pasar por la validación unique de la DB. Es transparente para el user.
     */
    public static function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['slug'])) {
            $data['slug'] = app(ShortLinkService::class)->generateSlug();
        }
        $data['created_by'] = Auth::id();
        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLinks::route('/'),
            'create' => Pages\CreateLink::route('/create'),
            'edit' => Pages\EditLink::route('/{record}/edit'),
            'view' => Pages\ViewLink::route('/{record}'),
        ];
    }

    /**
     * Scope canónico por rama (Fase 3).
     *
     * super_admin : ve todos los links (sin filtro).
     * supervisor  : ve links de todos los usuarios en su rama descendente
     *               (él mismo + sus jefes + creadores de sus jefes),
     *               filtrado por created_by.
     * jefe        : ve links creados por sus creadores directos + los suyos propios,
     *               filtrado por created_by.
     * creador     : ve solo sus propios links.
     *
     * Links pre-feature con account_id = null son invisibles para roles no super_admin
     * únicamente cuando fueron creados por usuarios fuera de su rama (el filtro
     * whereIn('created_by', ...) ya los excluye naturalmente).
     * Un link con account_id = null creado por un user dentro de la rama SÍ es visible
     * (el scope es por created_by, no por account_id).
     * Links con account_id = null y created_by = null (pre-feature migración antigua)
     * son visibles solo para super_admin porque ningún branchUserIds incluye null.
     */
    public static function getEloquentQuery(): Builder
    {
        $base = parent::getEloquentQuery();
        $user = Auth::user();

        if (!$user || $user->isSuperAdmin()) {
            return $base;
        }

        $branchIds = $user->branchUserIds()->all();

        return $base->whereIn('created_by', $branchIds);
    }
}
