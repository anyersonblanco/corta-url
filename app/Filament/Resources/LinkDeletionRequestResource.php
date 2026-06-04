<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LinkDeletionRequestResource\Pages;
use App\Models\Link;
use App\Models\LinkDeletionRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * LinkDeletionRequestResource — Flujo de aprobación de eliminación de links (Fase 4).
 *
 * Acceso:
 *  - creador    : sin acceso (canViewAny = false).
 *  - jefe       : ve solicitudes de los creadores que son sus hijos directos.
 *  - supervisor : ve solicitudes de toda su rama descendente.
 *  - super_admin: ve todas las solicitudes del sistema.
 *
 * Acciones disponibles (solo para solicitudes pending):
 *  - Aprobar: soft-delete el link + status=approved.
 *  - Rechazar: link sigue activo + status=rejected + review_note obligatorio.
 *
 * Badge en nav: contador de solicitudes pending en la rama del user actual.
 * Color rojo si hay al menos 1 pending.
 */
class LinkDeletionRequestResource extends Resource
{
    protected static ?string $model = LinkDeletionRequest::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';
    protected static string|\UnitEnum|null $navigationGroup = 'Acortador de enlaces';
    protected static ?string $modelLabel = 'Solicitud de eliminación';
    protected static ?string $pluralModelLabel = 'Solicitudes de eliminación';
    protected static ?string $navigationLabel = 'Solicitudes eliminación';
    protected static ?int $navigationSort = 5;

    // =========================================================================
    // Autorización
    // =========================================================================

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return false;
        }
        return ! $user->isCreador();
    }

    public static function canCreate(): bool
    {
        return false; // Las solicitudes solo se crean desde la acción en LinkResource.
    }

    public static function canEdit($record): bool
    {
        return false; // Sin edición directa — solo aprobar/rechazar via acciones.
    }

    // =========================================================================
    // Navigation badge
    // =========================================================================

    /**
     * Muestra el conteo de solicitudes pending en la rama del usuario actual.
     * Rojo si hay al menos 1, sin color si 0.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->where('status', LinkDeletionRequest::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // =========================================================================
    // Formulario (read-only, solo para vista)
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
                TextColumn::make('link.slug')
                    ->label('Enlace (slug)')
                    ->description(fn (LinkDeletionRequest $r): ?string => $r->link?->title)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('requester.name')
                    ->label('Solicitante')
                    ->description(fn (LinkDeletionRequest $r): ?string => $r->requester?->role)
                    ->searchable(),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(60)
                    ->tooltip(fn (LinkDeletionRequest $r): string => $r->reason),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        LinkDeletionRequest::STATUS_PENDING  => 'Pendiente',
                        LinkDeletionRequest::STATUS_APPROVED => 'Aprobada',
                        LinkDeletionRequest::STATUS_REJECTED => 'Rechazada',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        LinkDeletionRequest::STATUS_PENDING  => 'warning',
                        LinkDeletionRequest::STATUS_APPROVED => 'success',
                        LinkDeletionRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Solicitud creada')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('reviewer.name')
                    ->label('Revisor')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('reviewed_at')
                    ->label('Revisada al')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        LinkDeletionRequest::STATUS_PENDING  => 'Pendiente',
                        LinkDeletionRequest::STATUS_APPROVED => 'Aprobada',
                        LinkDeletionRequest::STATUS_REJECTED => 'Rechazada',
                    ]),

                Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('created_from')
                            ->label('Desde'),
                        \Filament\Forms\Components\DatePicker::make('created_until')
                            ->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date)
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date)
                            );
                    }),
            ])
            ->recordActions([
                // Acción Aprobar: soft-delete el link + status=approved.
                Action::make('aprobar')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar aprobación')
                    ->modalDescription('El enlace será archivado inmediatamente y dejará de redirigir. Esta acción no puede deshacerse desde el panel.')
                    ->modalSubmitActionLabel('Sí, aprobar y archivar')
                    ->disabled(fn (LinkDeletionRequest $r): bool => $r->status !== LinkDeletionRequest::STATUS_PENDING
                        || ! (Auth::user()?->canApproveDeletion($r->link?->loadMissing('deletionRequests') ?? new Link) ?? false))
                    ->action(function (LinkDeletionRequest $record): void {
                        $record->approve(Auth::user());

                        Notification::make()
                            ->title('Solicitud aprobada. El enlace fue archivado.')
                            ->success()
                            ->send();
                    }),

                // Acción Rechazar: link sigue activo + nota obligatoria.
                Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Rechazar solicitud de eliminación')
                    ->modalDescription('Ingresá el motivo del rechazo. El creador verá esta nota.')
                    ->schema([
                        Textarea::make('review_note')
                            ->label('Motivo del rechazo (obligatorio)')
                            ->placeholder('Explicá por qué rechazás esta solicitud...')
                            ->required()
                            ->maxLength(1000)
                            ->rows(4),
                    ])
                    ->disabled(fn (LinkDeletionRequest $r): bool => $r->status !== LinkDeletionRequest::STATUS_PENDING
                        || ! (Auth::user()?->canApproveDeletion($r->link?->loadMissing('deletionRequests') ?? new Link) ?? false))
                    ->action(function (LinkDeletionRequest $record, array $data): void {
                        $record->reject(Auth::user(), $data['review_note']);

                        Notification::make()
                            ->title('Solicitud rechazada. El enlace permanece activo.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }

    // =========================================================================
    // Query scopeado por rama
    // =========================================================================

    /**
     * super_admin: todas las solicitudes.
     * supervisor : solicitudes de toda su rama (requested_by en branchUserIds).
     * jefe       : solicitudes de sus creadores directos (children).
     * creador    : no llega (canViewAny = false).
     */
    public static function getEloquentQuery(): Builder
    {
        $base = parent::getEloquentQuery();
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || $user->isSuperAdmin()) {
            return $base;
        }

        // Filtrar por solicitantes en la rama del user actual.
        $branchIds = $user->branchUserIds()->all();

        return $base->whereIn('requested_by', $branchIds);
    }

    // =========================================================================
    // Páginas
    // =========================================================================

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLinkDeletionRequests::route('/'),
            'view'  => Pages\ViewLinkDeletionRequest::route('/{record}'),
        ];
    }
}
