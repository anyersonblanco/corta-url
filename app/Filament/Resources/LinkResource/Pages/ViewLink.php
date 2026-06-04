<?php

namespace App\Filament\Resources\LinkResource\Pages;

use App\Filament\Resources\LinkResource;
use App\Models\Link;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewLink extends ViewRecord
{
    protected static string $resource = LinkResource::class;

    public function getTitle(): string
    {
        return 'Enlace: ' . ($this->record->slug ?? '');
    }

    public function getSubheading(): ?string
    {
        return $this->record->title
            ? $this->record->title
            : 'Detalles, código QR y estadísticas del enlace corto';
    }

    public function getBreadcrumb(): string
    {
        return 'Detalles';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('abrirCorto')
                ->label('Abrir enlace corto')
                ->tooltip('Abre el enlace en pestaña nueva para verificar que redirige bien (cuenta como un click).')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(fn (Link $record) => $record->shortUrl(), true),
            EditAction::make()->label('Editar'),
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resumen del enlace')
                ->columns(2)
                ->components([
                    TextEntry::make('shortUrl')
                        ->label('Enlace corto (esto es lo que compartís)')
                        ->state(fn (Link $r) => $r->shortUrl())
                        ->copyable()
                        ->copyMessage('Enlace corto copiado al portapapeles')
                        ->weight('bold')
                        ->size('lg')
                        ->color('primary')
                        ->columnSpan(2),
                    TextEntry::make('destination_url')
                        ->label('URL de destino')
                        ->tooltip('La URL larga real a la que redirige')
                        ->url(fn ($state) => $state, true)
                        ->columnSpan(2),
                    TextEntry::make('clicks_count')
                        ->label('Total de clicks recibidos')
                        ->badge()
                        ->size('lg')
                        ->color(fn (int $state) => $state === 0 ? 'gray' : ($state < 10 ? 'warning' : 'success')),
                    TextEntry::make('is_active')
                        ->label('Estado')
                        ->badge()
                        ->color(fn ($state) => $state ? 'success' : 'danger')
                        ->formatStateUsing(fn ($state) => $state ? '✓ Activo' : '✗ Desactivado'),
                    TextEntry::make('title')
                        ->label('Descripción interna')
                        ->placeholder('—')
                        ->columnSpan(2),
                    TextEntry::make('tags')
                        ->label('Etiquetas')
                        ->badge()
                        ->separator(',')
                        ->placeholder('Sin etiquetas')
                        ->color('info'),
                    TextEntry::make('expires_at')
                        ->label('Expira el')
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('No expira')
                        ->color(fn ($state) => $state && now()->gt($state) ? 'danger' : null),
                    TextEntry::make('creator.name')
                        ->label('Creado por')
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label('Fecha de creación')
                        ->dateTime('d/m/Y H:i'),
                ]),

            Section::make('Código QR del enlace')
                ->description('Descargá este código para usarlo en flyers, presentaciones, eventos. Cuando alguien lo escanea, va al enlace corto y de ahí redirige al destino.')
                ->components([
                    ViewEntry::make('qr')
                        ->view('filament.wlink.qr-inline'),
                ]),

            Section::make('📊 Estadísticas y analytics del enlace')
                ->description('Cuántos clicks recibió, cuándo, desde qué países, con qué dispositivos y desde qué sitios. La IP de cada visitante se guarda truncada (privacy-aware).')
                ->components([
                    ViewEntry::make('clicks_recientes')
                        ->view('filament.wlink.clicks-recientes'),
                ]),
        ]);
    }
}
