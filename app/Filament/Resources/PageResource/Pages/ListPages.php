<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    public function getTitle(): string
    {
        return 'Páginas WLink (mini-landings tipo Linktree)';
    }

    public function getSubheading(): ?string
    {
        return 'Mini-páginas públicas con varios botones, ideales para link-in-bio de Instagram/TikTok o "todos mis enlaces" en un solo lugar.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Crear nueva página')
                ->tooltip('Crear una mini-página con varios botones que viva en una URL pública')
                ->icon('heroicon-o-plus'),
        ];
    }
}
