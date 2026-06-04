<?php

namespace Database\Factories;

use App\Models\Link;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factory para el modelo Link (WLink).
 *
 * Creado en Fase 2 para soportar tests de LinkAccountIdTest y AccountModelTest.
 * Los tests originales de WLink usan Link::create() directamente y no se afectan.
 *
 * @extends Factory<Link>
 */
class LinkFactory extends Factory
{
    protected $model = Link::class;

    public function definition(): array
    {
        return [
            'slug'            => Str::random(7),
            'destination_url' => fake()->url(),
            'title'           => fake()->sentence(3),
            'tags'            => null,
            'is_active'       => true,
            'expires_at'      => null,
            'password_hash'   => null,
            'created_by'      => null,
            'account_id'      => null, // Fase 2: null = "Sin asignar" por defecto
        ];
    }
}
