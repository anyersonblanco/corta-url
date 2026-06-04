<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'Usuarios del panel';
    }

    public function getSubheading(): ?string
    {
        return 'Gestión de accesos y jerarquía de roles de CortarLink.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo usuario')
                ->icon('heroicon-o-user-plus'),
        ];
    }
}
