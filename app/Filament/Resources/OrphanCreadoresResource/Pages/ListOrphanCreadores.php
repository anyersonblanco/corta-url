<?php

namespace App\Filament\Resources\OrphanCreadoresResource\Pages;

use App\Filament\Resources\OrphanCreadoresResource;
use Filament\Resources\Pages\ListRecords;

class ListOrphanCreadores extends ListRecords
{
    protected static string $resource = OrphanCreadoresResource::class;

    protected function getHeaderActions(): array
    {
        return []; // sin botón "Crear nuevo"
    }
}
