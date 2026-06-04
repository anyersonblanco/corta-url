<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return 'Usuario: ' . ($this->record->name ?? '');
    }

    public function getBreadcrumb(): string
    {
        return 'Detalles';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Editar'),
            DeleteAction::make()->label('Eliminar'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos del usuario')
                ->columns(2)
                ->components([
                    TextEntry::make('name')
                        ->label('Nombre'),

                    TextEntry::make('email')
                        ->label('Correo electrónico')
                        ->copyable(),

                    TextEntry::make('role')
                        ->label('Rol')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'super_admin' => 'Super Admin',
                            'supervisor'  => 'Supervisora',
                            'jefe'        => 'Jefe',
                            'creador'     => 'Creador',
                            default       => $state,
                        })
                        ->color(fn (string $state) => match ($state) {
                            'super_admin' => 'danger',
                            'supervisor'  => 'warning',
                            'jefe'        => 'info',
                            'creador'     => 'success',
                            default       => 'gray',
                        }),

                    TextEntry::make('is_active')
                        ->label('Estado')
                        ->badge()
                        ->formatStateUsing(fn (bool $state) => $state ? 'Activo' : 'Inactivo')
                        ->color(fn (bool $state) => $state ? 'success' : 'danger'),

                    TextEntry::make('parent.name')
                        ->label('Creado por')
                        ->placeholder('—'),

                    TextEntry::make('created_at')
                        ->label('Fecha de alta')
                        ->dateTime('d/m/Y H:i'),
                ]),

            Section::make('Usuarios bajo su cargo')
                ->description('Usuarios que este usuario creó directamente.')
                ->components([
                    TextEntry::make('children_count')
                        ->label('Subordinados directos')
                        ->state(fn (User $record) => $record->children()->count()),
                ]),
        ]);
    }
}
