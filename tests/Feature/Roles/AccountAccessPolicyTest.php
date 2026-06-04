<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests de autorización del AccountResource y AccountPolicy (Fase 2).
 *
 * Cubre:
 *  - super_admin ve todas las cuentas (Gate::before bypass)
 *  - supervisor ve solo sus cuentas (canViewAny + getEloquentQuery scope)
 *  - jefe ve solo las cuentas que tiene asignadas
 *  - creador no puede acceder al recurso (canViewAny = false)
 *  - canCreate: solo super_admin y supervisora
 *  - canEdit: super_admin y supervisora dueña; jefe no
 *  - Policy: view / create / update / delete por rol
 */
class AccountAccessPolicyTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSuperAdmin(string $suffix = '1'): User
    {
        return User::factory()->create([
            'role'  => 'super_admin',
            'email' => "sa{$suffix}@webtilia.com",
        ]);
    }

    private function makeSupervisor(string $suffix = '1'): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => "sv{$suffix}@webtilia.com",
        ]);
    }

    private function makeJefe(User $supervisor, string $suffix = '1'): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "jefe{$suffix}@webtilia.com",
            'parent_id' => $supervisor->id,
        ]);
    }

    private function makeCreador(User $jefe, string $suffix = '1'): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "cr{$suffix}@webtilia.com",
            'parent_id' => $jefe->id,
        ]);
    }

    private function makeAccount(User $supervisor, string $name = 'Cliente Test'): Account
    {
        return Account::create([
            'name'          => $name,
            'supervisor_id' => $supervisor->id,
            'is_active'     => true,
        ]);
    }

    // =========================================================================
    // canViewAny
    // =========================================================================

    public function test_super_admin_puede_ver_resource(): void
    {
        $sa = $this->makeSuperAdmin();
        $this->actingAs($sa);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canViewAny());
    }

    public function test_supervisor_puede_ver_resource(): void
    {
        $sv = $this->makeSupervisor('2');
        $this->actingAs($sv);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canViewAny());
    }

    public function test_jefe_puede_ver_resource(): void
    {
        $sv   = $this->makeSupervisor('3');
        $jefe = $this->makeJefe($sv);
        $this->actingAs($jefe);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canViewAny());
    }

    public function test_creador_NO_puede_ver_resource(): void
    {
        $sv     = $this->makeSupervisor('4');
        $jefe   = $this->makeJefe($sv, '2');
        $creador = $this->makeCreador($jefe);
        $this->actingAs($creador);
        $this->assertFalse(\App\Filament\Resources\AccountResource::canViewAny());
    }

    // =========================================================================
    // canCreate
    // =========================================================================

    public function test_super_admin_puede_crear_cuentas(): void
    {
        $sa = $this->makeSuperAdmin('2');
        $this->actingAs($sa);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canCreate());
    }

    public function test_supervisor_puede_crear_cuentas(): void
    {
        $sv = $this->makeSupervisor('5');
        $this->actingAs($sv);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canCreate());
    }

    public function test_jefe_NO_puede_crear_cuentas(): void
    {
        $sv   = $this->makeSupervisor('6');
        $jefe = $this->makeJefe($sv, '3');
        $this->actingAs($jefe);
        $this->assertFalse(\App\Filament\Resources\AccountResource::canCreate());
    }

    public function test_creador_NO_puede_crear_cuentas(): void
    {
        $sv     = $this->makeSupervisor('7');
        $jefe   = $this->makeJefe($sv, '4');
        $creador = $this->makeCreador($jefe, '2');
        $this->actingAs($creador);
        $this->assertFalse(\App\Filament\Resources\AccountResource::canCreate());
    }

    // =========================================================================
    // canEdit
    // =========================================================================

    public function test_super_admin_puede_editar_cualquier_cuenta(): void
    {
        $sv      = $this->makeSupervisor('8');
        $account = $this->makeAccount($sv);
        $sa      = $this->makeSuperAdmin('3');
        $this->actingAs($sa);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canEdit($account));
    }

    public function test_supervisor_puede_editar_su_propia_cuenta(): void
    {
        $sv      = $this->makeSupervisor('9');
        $account = $this->makeAccount($sv, 'Mi Cuenta');
        $this->actingAs($sv);
        $this->assertTrue(\App\Filament\Resources\AccountResource::canEdit($account));
    }

    public function test_supervisor_NO_puede_editar_cuenta_ajena(): void
    {
        $sv1     = $this->makeSupervisor('10');
        $sv2     = $this->makeSupervisor('11');
        $account = $this->makeAccount($sv1, 'Cuenta de sv1');
        $this->actingAs($sv2);
        $this->assertFalse(\App\Filament\Resources\AccountResource::canEdit($account));
    }

    public function test_jefe_NO_puede_editar_cuenta(): void
    {
        $sv      = $this->makeSupervisor('12');
        $jefe    = $this->makeJefe($sv, '5');
        $account = $this->makeAccount($sv, 'Cuenta Jefe');
        $account->assignTo($jefe, $sv);
        $this->actingAs($jefe);
        $this->assertFalse(\App\Filament\Resources\AccountResource::canEdit($account));
    }

    // =========================================================================
    // Policy view / create / update / delete
    // =========================================================================

    public function test_policy_view_supervisor_ve_sus_cuentas(): void
    {
        $sv      = $this->makeSupervisor('13');
        $account = $this->makeAccount($sv, 'Cuenta Policy');

        $this->assertTrue(Gate::forUser($sv)->allows('view', $account));
    }

    public function test_policy_view_supervisor_NO_ve_cuentas_ajenas(): void
    {
        $sv1     = $this->makeSupervisor('14');
        $sv2     = $this->makeSupervisor('15');
        $account = $this->makeAccount($sv1, 'Cuenta ajena');

        $this->assertFalse(Gate::forUser($sv2)->allows('view', $account));
    }

    public function test_policy_view_jefe_ve_cuentas_asignadas(): void
    {
        $sv      = $this->makeSupervisor('16');
        $jefe    = $this->makeJefe($sv, '6');
        $account = $this->makeAccount($sv, 'Cuenta Jefe Policy');
        $account->assignTo($jefe, $sv);

        $this->assertTrue(Gate::forUser($jefe)->allows('view', $account));
    }

    public function test_policy_view_jefe_NO_ve_cuenta_no_asignada(): void
    {
        $sv      = $this->makeSupervisor('17');
        $jefe    = $this->makeJefe($sv, '7');
        $account = $this->makeAccount($sv, 'Cuenta No Asignada');
        // NO asignamos al jefe

        $this->assertFalse(Gate::forUser($jefe)->allows('view', $account));
    }

    public function test_policy_create_supervisor_puede(): void
    {
        $sv = $this->makeSupervisor('18');
        $this->assertTrue(Gate::forUser($sv)->allows('create', Account::class));
    }

    public function test_policy_create_jefe_no_puede(): void
    {
        $sv   = $this->makeSupervisor('19');
        $jefe = $this->makeJefe($sv, '8');
        $this->assertFalse(Gate::forUser($jefe)->allows('create', Account::class));
    }

    public function test_policy_update_supervisor_duena_puede(): void
    {
        $sv      = $this->makeSupervisor('20');
        $account = $this->makeAccount($sv, 'Cuenta Duena');
        $this->assertTrue(Gate::forUser($sv)->allows('update', $account));
    }

    public function test_policy_update_supervisor_no_duena_no_puede(): void
    {
        $sv1     = $this->makeSupervisor('21');
        $sv2     = $this->makeSupervisor('22');
        $account = $this->makeAccount($sv1, 'Cuenta sv1');
        $this->assertFalse(Gate::forUser($sv2)->allows('update', $account));
    }

    public function test_policy_super_admin_bypass_todos_los_gates(): void
    {
        $sa      = $this->makeSuperAdmin('4');
        $sv      = $this->makeSupervisor('23');
        $account = $this->makeAccount($sv, 'Cuenta SA');

        $this->assertTrue(Gate::forUser($sa)->allows('view', $account));
        $this->assertTrue(Gate::forUser($sa)->allows('create', Account::class));
        $this->assertTrue(Gate::forUser($sa)->allows('update', $account));
        $this->assertTrue(Gate::forUser($sa)->allows('delete', $account));
    }
}
