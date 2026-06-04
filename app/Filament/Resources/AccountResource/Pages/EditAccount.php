<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    public function getTitle(): string
    {
        return 'Editar cuenta: ' . ($this->record->name ?? '');
    }

    public function getBreadcrumb(): string
    {
        return 'Editar';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Ver detalles'),
            DeleteAction::make()->label('Eliminar'),
        ];
    }
}
