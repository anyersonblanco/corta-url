<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del gate canAccessPanel().
 *
 * Reglas: solo emails @webtilia.com Y is_active=true entran al panel.
 * Desactivar un usuario lo deja inmediatamente fuera sin tocar nada más.
 */
class CanAccessPanelTest extends TestCase
{
    use RefreshDatabase;

    private function panel(): Panel
    {
        // canAccessPanel() ignora el Panel, solo mira $this->email + $this->is_active.
        // Cualquier Panel vacío sirve para satisfacer la firma.
        return new Panel();
    }

    public function test_webtilia_email_activo_entra(): void
    {
        $user = User::factory()->create([
            'email'     => 'ablanco@webtilia.com',
            'is_active' => true,
        ]);
        $this->assertTrue($user->canAccessPanel($this->panel()));
    }

    public function test_webtilia_email_desactivado_NO_entra(): void
    {
        $user = User::factory()->create([
            'email'     => 'ablanco@webtilia.com',
            'is_active' => false,
        ]);
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_email_no_webtilia_NO_entra_aunque_este_activo(): void
    {
        $user = User::factory()->create([
            'email'     => 'extern@gmail.com',
            'is_active' => true,
        ]);
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_email_no_webtilia_y_desactivado_NO_entra(): void
    {
        $user = User::factory()->create([
            'email'     => 'extern@gmail.com',
            'is_active' => false,
        ]);
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }

    public function test_super_admin_desactivado_tampoco_entra(): void
    {
        $user = User::factory()->create([
            'role'      => 'super_admin',
            'email'     => 'sa@webtilia.com',
            'is_active' => false,
        ]);
        $this->assertFalse($user->canAccessPanel($this->panel()));
    }
}
