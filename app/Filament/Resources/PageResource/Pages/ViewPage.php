<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewPage extends ViewRecord
{
    protected static string $resource = PageResource::class;

    public function getTitle(): string
    {
        return 'Página: ' . ($this->record->title ?? '');
    }

    public function getSubheading(): ?string
    {
        return $this->record->description
            ?: 'Estadísticas, vista previa y botones de la página WLink';
    }

    public function getBreadcrumb(): string
    {
        return 'Estadísticas';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('abrirPublica')
                ->label('Abrir página pública')
                ->tooltip('Ver la página renderizada como la verá el visitante (suma una visita)')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(fn (Page $record) => $record->publicUrl(), true),
            EditAction::make()->label('Editar página'),
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Resumen de la página')
                ->columns(2)
                ->components([
                    TextEntry::make('publicUrl')
                        ->label('URL pública (esto es lo que compartís)')
                        ->state(fn (Page $r) => $r->publicUrl())
                        ->copyable()
                        ->copyMessage('URL pública copiada al portapapeles')
                        ->weight('bold')
                        ->size('lg')
                        ->color('primary')
                        ->columnSpan(2),
                    TextEntry::make('views_count')
                        ->label('Visitas totales a la página')
                        ->tooltip('Cantidad de veces que alguien abrió /p/' . ($this->record->slug ?? ''))
                        ->badge()
                        ->size('lg')
                        ->color(fn (int $state) => $state === 0 ? 'gray' : ($state < 10 ? 'warning' : 'success')),
                    TextEntry::make('total_engagements')
                        ->label('Interacciones (clicks a botones)')
                        ->state(fn (Page $r) => $r->totalEngagements())
                        ->tooltip('Suma de clicks a todos los botones de la página')
                        ->badge()
                        ->size('lg')
                        ->color('info'),
                    TextEntry::make('title')
                        ->label('Título')
                        ->columnSpan(2),
                    TextEntry::make('description')
                        ->label('Descripción')
                        ->placeholder('—')
                        ->columnSpan(2),
                    TextEntry::make('is_active')
                        ->label('Estado')
                        ->badge()
                        ->color(fn ($state) => $state ? 'success' : 'danger')
                        ->formatStateUsing(fn ($state) => $state ? '✓ Activa' : '✗ Desactivada'),
                    TextEntry::make('creator.name')
                        ->label('Creada por')
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label('Fecha de creación')
                        ->dateTime('d/m/Y H:i'),
                ]),

            Section::make('📊 Estadísticas de visitas e interacciones')
                ->description('Cuántas personas vieron la página, cuándo, desde qué países y qué botones más clickearon.')
                ->components([
                    ViewEntry::make('analytics')
                        ->view('filament.wlink.page-analytics'),
                ]),

            Section::make('Botones de esta página')
                ->description('Lista de botones con sus clicks individuales. Hacé click en "Editar" arriba para modificarlos.')
                ->collapsible()
                ->components([
                    ViewEntry::make('botones')
                        ->view('filament.wlink.page-buttons-list'),
                ]),
        ]);
    }
}
