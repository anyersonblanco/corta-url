<?php

namespace App\Filament\Resources\PageResource\Pages;

use App\Filament\Resources\PageResource;
use App\Models\Page;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    public function getTitle(): string
    {
        return 'Editar Página: ' . ($this->record->title ?? '');
    }

    public function getBreadcrumb(): string
    {
        return 'Editar';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('abrirPublica')
                ->label('Abrir página pública')
                ->tooltip('Ver la página renderizada como la verá el visitante')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(fn (Page $record) => $record->publicUrl(), true),
            ViewAction::make()->label('Ver estadísticas'),
            DeleteAction::make()->label('Eliminar página'),
        ];
    }
}
