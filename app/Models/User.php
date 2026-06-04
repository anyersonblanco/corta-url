<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /**
     * Filament 4 PROD bloquea acceso al panel si User NO implementa este metodo
     * (incluso si el login fue exitoso). En local Filament 4 deja entrar a cualquiera
     * pero en PROD la auth via Livewire devuelve 403 al redirect a /admin.
     * Restriccion: solo emails @webtilia.com (equipo interno).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return str_ends_with(strtolower($this->email ?? ''), '@webtilia.com');
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
