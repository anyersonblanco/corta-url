<?php

namespace App\Filament\Resources\LinkDeletionRequestResource\Pages;

use App\Filament\Resources\LinkDeletionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListLinkDeletionRequests extends ListRecords
{
    protected static string $resource = LinkDeletionRequestResource::class;

    public function getTitle(): string
    {
        return 'Solicitudes de eliminación';
    }

    protected function getHeaderActions(): array
    {
        return []; // Sin acción de crear — las solicitudes vienen de LinkResource.
    }
}
