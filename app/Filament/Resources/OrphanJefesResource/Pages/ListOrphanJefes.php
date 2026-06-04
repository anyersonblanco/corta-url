<?php

namespace App\Filament\Resources\OrphanJefesResource\Pages;

use App\Filament\Resources\OrphanJefesResource;
use Filament\Resources\Pages\ListRecords;

class ListOrphanJefes extends ListRecords
{
    protected static string $resource = OrphanJefesResource::class;

    protected function getHeaderActions(): array
    {
        return []; // sin botón "Crear nuevo"
    }
}
