<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests de política de creación de usuarios por rol (Fase 1).
 *
 * Verifica que la lógica de `creatableRoles()` y la visibilidad
 * del UserResource respetan las restricciones del brief:
 *
 *  - super_admin puede "crear" cualquier rol
 *  - supervisor solo puede crear jefe (no supervisor, no creador, no super_admin)
 *  - jefe solo puede crear creador (no otro rol)
 *  - creador no puede acceder al UserResource (canViewAny = false)
 *
 * Los tests de Gate usan `canViewAny` a través de
 * UserResource::canViewAny() directamente, y la lógica de roles via creatableRoles().
 */
class UserCreationPolicyTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // super_admin: puede crear cualquier rol
    // =========================================================================

    public function test_super_admin_puede_crear_cualquier_rol(): void
    {
        $sa = User::factory()->create(['role' => 'super_admin', 'email' => 'sa@webtilia.com']);

        $this->assertContains('super_admin', $sa->creatableRoles());
        $this->assertContains('supervisor', $sa->creatableRoles());
        $this->assertContains('jefe', $sa->creatableRoles());
        $this->assertContains('creador', $sa->creatableRoles());
    }

    public function test_super_admin_puede_ver_user_resource(): void
    {
        $sa = User::factory()->create(['role' => 'super_admin', 'email' => 'sa2@webtilia.com']);
        $this->actingAs($sa);

        $this->assertTrue(\App\Filament\Resources\UserResource::canViewAny());
    }

    // =========================================================================
    // supervisor: solo puede crear jefe
    // =========================================================================

    public function test_supervisor_solo_puede_crear_jefe(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'sv@webtilia.com']);

        $this->assertSame(['jefe'], $sv->creatableRoles());
    }

    public function test_supervisor_no_puede_crear_supervisor(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'sv2@webtilia.com']);

        $this->assertNotContains('supervisor', $sv->creatableRoles());
    }

    public function test_supervisor_no_puede_crear_creador(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'sv3@webtilia.com']);

        $this->assertNotContains('creador', $sv->creatableRoles());
    }

    public function test_supervisor_no_puede_crear_super_admin(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'sv4@webtilia.com']);

        $this->assertNotContains('super_admin', $sv->creatableRoles());
    }

    public function test_supervisor_puede_ver_user_resource(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'sv5@webtilia.com']);
        $this->actingAs($sv);

        $this->assertTrue(\App\Filament\Resources\UserResource::canViewAny());
    }

    // =========================================================================
    // jefe: solo puede crear creador
    // =========================================================================

    public function test_jefe_solo_puede_crear_creador(): void
    {
        $j = User::factory()->create(['role' => 'jefe', 'email' => 'j@webtilia.com']);

        $this->assertSame(['creador'], $j->creatableRoles());
    }

    public function test_jefe_no_puede_crear_jefe(): void
    {
        $j = User::factory()->create(['role' => 'jefe', 'email' => 'j2@webtilia.com']);

        $this->assertNotContains('jefe', $j->creatableRoles());
    }

    public function test_jefe_no_puede_crear_supervisor(): void
    {
        $j = User::factory()->create(['role' => 'jefe', 'email' => 'j3@webtilia.com']);

        $this->assertNotContains('supervisor', $j->creatableRoles());
    }

    public function test_jefe_no_puede_crear_super_admin(): void
    {
        $j = User::factory()->create(['role' => 'jefe', 'email' => 'j4@webtilia.com']);

        $this->assertNotContains('super_admin', $j->creatableRoles());
    }

    public function test_jefe_puede_ver_user_resource(): void
    {
        $j = User::factory()->create(['role' => 'jefe', 'email' => 'j5@webtilia.com']);
        $this->actingAs($j);

        $this->assertTrue(\App\Filament\Resources\UserResource::canViewAny());
    }

    // =========================================================================
    // creador: no puede acceder al recurso
    // =========================================================================

    public function test_creador_no_puede_crear_ningun_rol(): void
    {
        $c = User::factory()->create(['role' => 'creador', 'email' => 'cr@webtilia.com']);

        $this->assertSame([], $c->creatableRoles());
    }

    public function test_creador_no_puede_ver_user_resource(): void
    {
        $c = User::factory()->create(['role' => 'creador', 'email' => 'cr2@webtilia.com']);
        $this->actingAs($c);

        $this->assertFalse(\App\Filament\Resources\UserResource::canViewAny());
    }

    // =========================================================================
    // Gate::before bypass para super_admin
    // =========================================================================

    public function test_gate_before_bypass_super_admin(): void
    {
        $sa = User::factory()->create(['role' => 'super_admin', 'email' => 'sa3@webtilia.com']);

        // El Gate::before debe retornar true para cualquier ability
        $this->assertTrue(Gate::forUser($sa)->allows('editar-cualquier-cosa'));
        $this->assertTrue(Gate::forUser($sa)->allows('delete'));
        $this->assertTrue(Gate::forUser($sa)->allows('create'));
    }

    public function test_gate_before_no_bypass_para_no_super_admin(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'sv6@webtilia.com']);

        // Para una ability no definida, Gate retorna false (no bypass, no define)
        // Esto confirma que Gate::before retorna null para no-super_admin
        $this->assertFalse(Gate::forUser($sv)->allows('ability-no-definida-en-ningun-lado'));
    }
}
