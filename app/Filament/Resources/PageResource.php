<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageResource\Pages;
use App\Models\Link;
use App\Models\Page;
use App\Services\ShortLinkService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static string|\UnitEnum|null $navigationGroup = 'Mini-páginas (Linktree)';
    protected static ?string $modelLabel = 'Mini-página';
    protected static ?string $pluralModelLabel = 'Mini-páginas';
    protected static ?string $navigationLabel = 'Mini-páginas';
    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Configuración de la página')
                ->columnSpanFull()
                ->tabs([

                    // ============== TAB 1: EDITAR ==============
                    Tab::make('Editar')
                        ->icon('heroicon-o-pencil-square')
                        ->schema([
                            Section::make('Información básica')
                                ->description('Lo que el visitante ve cuando abre tu Página.')
                                ->components([
                                    TextInput::make('title')
                                        ->label('Título de la página')
                                        ->placeholder('Ej: Webtilia · Todos nuestros enlaces')
                                        ->helperText('Aparece como encabezado grande arriba de los botones.')
                                        ->required()
                                        ->maxLength(255),
                                    TextInput::make('slug')
                                        ->label('Slug (URL pública)')
                                        ->placeholder('Ej: webtilia, eventos2026 (dejá vacío para autogenerar)')
                                        ->helperText('La URL final va a ser ' . config('wlink.short_base_url') . config('wlink.pages_prefix') . '/{slug}. Solo letras/números/guiones. Si lo dejás vacío, se genera uno aleatorio de 7 caracteres.')
                                        ->maxLength(64)
                                        // Misma razón que LinkResource: uniqueness va por
                                        // ->unique(ignoreRecord: true) nativo de Filament
                                        // (request()->route('record') resuelve null en /livewire/update).
                                        ->rules([
                                            fn () => function (string $attribute, $value, \Closure $fail) {
                                                if (empty($value)) return;
                                                if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $value)) {
                                                    $fail('El slug debe tener entre 2 y 64 caracteres y solo puede contener letras, números, guiones y guiones bajos.');
                                                    return;
                                                }
                                                if (in_array(strtolower($value), ShortLinkService::RESERVED_SLUGS, true)) {
                                                    $fail('Ese slug está reservado por el sistema. Probá otro.');
                                                }
                                            },
                                        ])
                                        ->unique(ignoreRecord: true)
                                        ->validationMessages([
                                            'unique' => 'Ese slug ya está en uso por otra Página.',
                                        ]),
                                    Textarea::make('description')
                                        ->label('Descripción corta (opcional)')
                                        ->placeholder('Ej: Somos Webtilia, agencia digital peruana. Estos son nuestros canales.')
                                        ->helperText('Aparece debajo del título. Una o dos líneas. Se usa también en el preview de WhatsApp/Twitter cuando alguien comparte el enlace.')
                                        ->rows(2)
                                        ->maxLength(500),
                                    TextInput::make('avatar_url')
                                        ->label('URL de avatar / logo (opcional)')
                                        ->placeholder('https://webtilia.com/logo.png')
                                        ->helperText('Imagen circular que aparece arriba del título. Recomendado: cuadrada, mínimo 200x200px. Si lo dejás vacío, se muestra la inicial del título.')
                                        ->url()
                                        ->maxLength(500),
                                    Toggle::make('is_active')
                                        ->label('Página activa')
                                        ->helperText('Si está desactivada, los visitantes ven una página "Esta página no existe o fue desactivada".')
                                        ->default(true),
                                ])
                                ->columns(1),

                            Section::make('Botones de la página')
                                ->description('Cada botón es un enlace que aparece en la página. Arrastrá para reordenar. Podés apuntar a un enlace corto interno o a una URL externa directa.')
                                ->components([
                                    Repeater::make('buttons')
                                        ->label('Botones')
                                        ->hiddenLabel()
                                        ->relationship('buttons')
                                        ->orderColumn('order')
                                        ->reorderable()
                                        ->reorderableWithDragAndDrop()
                                        ->collapsible()
                                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? 'Botón sin nombre')
                                        ->defaultItems(0)
                                        ->addActionLabel('+ Agregar botón')
                                        ->schema([
                                            TextInput::make('label')
                                                ->label('Texto del botón')
                                                ->placeholder('Ej: 🌐 Visitar nuestro sitio')
                                                ->required()
                                                ->maxLength(200),
                                            TextInput::make('icon')
                                                ->label('Icono o emoji (opcional)')
                                                ->placeholder('Ej: 🚀 · 📧 · 🛒 · 💬')
                                                ->helperText('Pegá un emoji directo. Aparece a la izquierda del texto.')
                                                ->maxLength(8),
                                            Select::make('link_id')
                                                ->label('Apuntar a un enlace corto existente (opcional)')
                                                ->helperText('Si elegís un Link interno, los clicks se cuentan también en ese Link. Si lo dejás vacío, usá la URL externa abajo.')
                                                ->relationship('link', 'slug')
                                                ->options(fn () => Link::active()->get()->mapWithKeys(fn ($l) => [$l->id => $l->slug . ' → ' . \Illuminate\Support\Str::limit($l->destination_url, 50)])->all())
                                                ->searchable()
                                                ->placeholder('— No usar enlace interno —'),
                                            TextInput::make('external_url')
                                                ->label('O URL externa directa')
                                                ->placeholder('https://misitio.com/promo')
                                                ->helperText('Solo se usa si NO elegiste un enlace interno arriba.')
                                                ->url()
                                                ->maxLength(2048),
                                            Toggle::make('is_active')
                                                ->label('Botón activo')
                                                ->default(true)
                                                ->inline(false),
                                        ])
                                        ->columns(2),
                                ])
                                ->columns(1),
                        ]),

                    // ============== TAB 2: DISEÑO ==============
                    Tab::make('Diseño')
                        ->icon('heroicon-o-paint-brush')
                        ->schema([
                            Section::make('Estilo visual de la página')
                                ->description('Los colores y tipografía con que se ve la página pública. Por defecto usa los colores Webtilia (azul + amarillo).')
                                ->components([
                                    Select::make('design_json.template')
                                        ->label('Plantilla')
                                        ->helperText('Estructura visual base. "Clásico" = botones grandes verticales. "Compacto" = botones chicos densos.')
                                        ->options([
                                            'clasico' => 'Clásico (botones grandes verticales)',
                                            'compacto' => 'Compacto (botones chicos densos)',
                                        ])
                                        ->default('clasico')
                                        ->required(),
                                    Select::make('design_json.font_family')
                                        ->label('Tipografía')
                                        ->options([
                                            'system' => 'Sans-serif del sistema (default)',
                                            'serif' => 'Serif (estilo clásico, ej: Georgia)',
                                            'mono' => 'Monoespaciada (estilo dev)',
                                        ])
                                        ->default('system'),
                                    ColorPicker::make('design_json.bg_color')
                                        ->label('Color de fondo (inicio del gradient)')
                                        ->default('#0066FF')
                                        ->helperText('El fondo es un gradient diagonal de este color al "Color final del gradient" de abajo.'),
                                    ColorPicker::make('design_json.bg_color_to')
                                        ->label('Color final del gradient')
                                        ->default('#0044BB'),
                                    ColorPicker::make('design_json.text_color')
                                        ->label('Color del texto (título y descripción)')
                                        ->default('#FFFFFF'),
                                    ColorPicker::make('design_json.button_bg')
                                        ->label('Color de fondo de los botones')
                                        ->default('#FFFFFF'),
                                    ColorPicker::make('design_json.button_text')
                                        ->label('Color del texto de los botones')
                                        ->default('#0044BB'),
                                    Select::make('design_json.button_style')
                                        ->label('Estilo de botón')
                                        ->options([
                                            'solid' => 'Sólido (relleno)',
                                            'outline' => 'Solo borde (outline)',
                                            'ghost' => 'Translúcido (ghost)',
                                        ])
                                        ->default('solid'),
                                    TextInput::make('design_json.button_radius')
                                        ->label('Borde redondeado de los botones (px)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(40)
                                        ->default(12)
                                        ->suffix('px')
                                        ->helperText('0 = botones cuadrados. 40 = botones tipo "pill" redondos.'),
                                ])
                                ->columns(2),
                        ]),

                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Página')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->copyable()
                    ->copyMessage('Slug copiado')
                    ->color('primary'),
                TextColumn::make('public_url')
                    ->label('URL pública')
                    ->state(fn (Page $r) => $r->publicUrl())
                    ->copyable()
                    ->copyMessage('URL pública copiada')
                    ->limit(40)
                    ->color('gray'),
                TextColumn::make('views_count')
                    ->label('Visitas')
                    ->tooltip('Cantidad de veces que alguien abrió la página')
                    ->alignCenter()
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'gray' : ($state < 10 ? 'warning' : 'success')),
                TextColumn::make('buttons_count')
                    ->label('Botones')
                    ->tooltip('Cantidad de botones que tiene la página')
                    ->counts('buttons')
                    ->alignCenter()
                    ->badge()
                    ->color('info'),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Activa'),
                TextColumn::make('creator.name')
                    ->label('Creada por')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Fecha de creación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')->label('Solo activas'),
            ])
            ->recordActions([
                Action::make('abrirPublica')
                    ->label('Abrir')
                    ->tooltip('Abre la página pública en una pestaña nueva (cuenta como una visita)')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('primary')
                    ->url(fn (Page $r) => $r->publicUrl(), true),
                ViewAction::make()->label('Detalles'),
                EditAction::make()->label('Editar'),
                DeleteAction::make()->label('Eliminar'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()->label('Eliminar seleccionadas'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
            'view' => Pages\ViewPage::route('/{record}'),
        ];
    }

    /**
     * Scope canónico por rama (Fase 3).
     *
     * Page tiene columna created_by (FK nullable a users).
     * El scope es idéntico al de LinkResource pero sobre la tabla pages.
     *
     * super_admin : ve todas las páginas (sin filtro).
     * supervisor  : ve páginas creadas por usuarios de su rama descendente.
     * jefe        : ve páginas creadas por sus creadores directos + las suyas.
     * creador     : ve solo sus propias páginas.
     *
     * Páginas con created_by = null (pre-feature sin asignar) son visibles solo
     * para super_admin porque ningún branchUserIds() incluye null en la query.
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
