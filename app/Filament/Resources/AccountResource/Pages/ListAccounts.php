<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    public function getTitle(): string
    {
        return 'Cuentas de clientes';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva cuenta'),
        ];
    }
}
