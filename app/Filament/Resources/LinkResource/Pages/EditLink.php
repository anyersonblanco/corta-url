<?php

namespace App\Filament\Resources\LinkResource\Pages;

use App\Filament\Resources\LinkResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditLink extends EditRecord
{
    protected static string $resource = LinkResource::class;

    public function getTitle(): string
    {
        return 'Editar enlace: ' . ($this->record->slug ?? '');
    }

    public function getBreadcrumb(): string
    {
        return 'Editar';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Ver detalles'),
            DeleteAction::make()->label('Eliminar enlace'),
        ];
    }
}
