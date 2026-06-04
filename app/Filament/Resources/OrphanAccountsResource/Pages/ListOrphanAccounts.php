<?php

namespace App\Filament\Resources\OrphanAccountsResource\Pages;

use App\Filament\Resources\OrphanAccountsResource;
use Filament\Resources\Pages\ListRecords;

class ListOrphanAccounts extends ListRecords
{
    protected static string $resource = OrphanAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return []; // sin botón "Crear nuevo"
    }
}
