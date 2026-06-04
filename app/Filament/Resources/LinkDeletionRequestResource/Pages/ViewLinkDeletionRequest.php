<?php

namespace App\Filament\Resources\LinkDeletionRequestResource\Pages;

use App\Filament\Resources\LinkDeletionRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewLinkDeletionRequest extends ViewRecord
{
    protected static string $resource = LinkDeletionRequestResource::class;

    public function getTitle(): string
    {
        return 'Detalle de solicitud';
    }
}
