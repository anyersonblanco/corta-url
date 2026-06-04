<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'Crear nuevo usuario';
    }

    /**
     * Al crear un usuario, si el creador no es super_admin, se fuerza
     * el parent_id al usuario autenticado (el form lo oculta pero lo seteamos
     * aquí como defensa adicional).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var User|null $authUser */
        $authUser = Auth::user();

        if ($authUser !== null && ! $authUser->isSuperAdmin()) {
            $data['parent_id'] = $authUser->id;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
