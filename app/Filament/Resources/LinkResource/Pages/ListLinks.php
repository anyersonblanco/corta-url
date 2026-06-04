<?php

namespace App\Filament\Resources\LinkResource\Pages;

use App\Filament\Resources\LinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLinks extends ListRecords
{
    protected static string $resource = LinkResource::class;

    public function getTitle(): string
    {
        return 'Enlaces acortados';
    }

    public function getSubheading(): ?string
    {
        return 'Todos los enlaces cortos creados por el equipo. Hacé click en "Abrir" para probar uno, en "QR" para descargar el código.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Crear enlace corto')
                ->tooltip('Acortar una URL larga. Podés personalizar el slug o dejar que el sistema genere uno aleatorio.')
                ->icon('heroicon-o-plus'),
        ];
    }
}
