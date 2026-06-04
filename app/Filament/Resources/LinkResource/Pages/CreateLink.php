<?php

namespace App\Filament\Resources\LinkResource\Pages;

use App\Filament\Resources\LinkResource;
use App\Services\ShortLinkService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateLink extends CreateRecord
{
    protected static string $resource = LinkResource::class;

    public function getTitle(): string
    {
        return 'Crear enlace corto';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['slug'])) {
            $data['slug'] = app(ShortLinkService::class)->generateSlug();
        }
        $data['created_by'] = Auth::id();
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Al crear, redirige al View del nuevo link para que el user vea el QR + enlace listo para copiar
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
