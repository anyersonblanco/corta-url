<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Tests de la cadena de asignación de cuentas (Fase 2).
 *
 * Verifica que la política de assignToUser respeta las reglas:
 *  - supervisora asigna cuenta a su jefe: OK
 *  - jefe NO puede asignar si él mismo no tiene la cuenta
 *  - jefe SÍ puede asignar a su creador cuando ya tiene la cuenta
 *  - super_admin puede asignar a cualquiera sin restricciones
 *  - supervisora no puede asignar a usuario fuera de su rama
 *  - creador nunca puede asignar
 *
 * Nota: assignToUser pasa 3 args a la Policy (actor, account, target).
 * Gate::forUser($actor)->allows('assignToUser', [$account, $target]) es el
 * patrón canónico para policies con más de un argumento en Laravel.
 */
class AccountAssignmentChainTest extends TestCase
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

    private function makeAccount(User $supervisor, string $name = 'Cuenta Test'): Account
    {
        return Account::create([
            'name'          => $name,
            'supervisor_id' => $supervisor->id,
            'is_active'     => true,
        ]);
    }

    // =========================================================================
    // Supervisora asigna a jefe
    // =========================================================================

    public function test_supervisora_puede_asignar_cuenta_a_su_jefe(): void
    {
        $sv      = $this->makeSupervisor();
        $jefe    = $this->makeJefe($sv);
        $account = $this->makeAccount($sv);

        $this->assertTrue(
            Gate::forUser($sv)->allows('assignToUser', [$account, $jefe])
        );
    }

    public function test_supervisora_NO_puede_asignar_a_jefe_de_otra_rama(): void
    {
        $sv1     = $this->makeSupervisor('2');
        $sv2     = $this->makeSupervisor('3');
        $jefe_sv2 = $this->makeJefe($sv2, '2');
        $account = $this->makeAccount($sv1, 'Cuenta sv1');

        // sv1 no supervisa al jefe de sv2
        $this->assertFalse(
            Gate::forUser($sv1)->allows('assignToUser', [$account, $jefe_sv2])
        );
    }

    public function test_supervisora_NO_puede_asignar_cuenta_ajena_a_su_jefe(): void
    {
        $sv1     = $this->makeSupervisor('4');
        $sv2     = $this->makeSupervisor('5');
        $jefe    = $this->makeJefe($sv1, '3'); // jefe de sv1
        $account = $this->makeAccount($sv2, 'Cuenta de sv2'); // cuenta de sv2

        // sv1 es dueña del jefe pero NO de la cuenta
        $this->assertFalse(
            Gate::forUser($sv1)->allows('assignToUser', [$account, $jefe])
        );
    }

    // =========================================================================
    // Jefe intenta asignar SIN tener la cuenta
    // =========================================================================

    public function test_jefe_NO_puede_asignar_cuenta_que_no_tiene(): void
    {
        $sv      = $this->makeSupervisor('6');
        $jefe    = $this->makeJefe($sv, '4');
        $creador = $this->makeCreador($jefe);
        $account = $this->makeAccount($sv, 'Cuenta Sin Asignar Jefe');
        // NO asignamos la cuenta al jefe

        $this->assertFalse(
            Gate::forUser($jefe)->allows('assignToUser', [$account, $creador])
        );
    }

    // =========================================================================
    // Jefe asigna TENIENDO la cuenta
    // =========================================================================

    public function test_jefe_puede_asignar_cuenta_asignada_a_su_creador(): void
    {
        $sv      = $this->makeSupervisor('7');
        $jefe    = $this->makeJefe($sv, '5');
        $creador = $this->makeCreador($jefe, '2');
        $account = $this->makeAccount($sv, 'Cuenta Asignada Jefe');

        // Primero la supervisora asigna la cuenta al jefe
        $account->assignTo($jefe, $sv);

        // Ahora el jefe puede asignar a su creador
        $this->assertTrue(
            Gate::forUser($jefe)->allows('assignToUser', [$account, $creador])
        );
    }

    public function test_jefe_NO_puede_asignar_cuenta_a_creador_de_otra_rama(): void
    {
        $sv      = $this->makeSupervisor('8');
        $jefe1   = $this->makeJefe($sv, '6');
        $jefe2   = $this->makeJefe($sv, '7');
        $creador_jefe2 = $this->makeCreador($jefe2, '3');
        $account = $this->makeAccount($sv, 'Cuenta Rama Cruzada');

        // jefe1 tiene la cuenta asignada
        $account->assignTo($jefe1, $sv);

        // Pero jefe1 NO supervisa al creador de jefe2
        $this->assertFalse(
            Gate::forUser($jefe1)->allows('assignToUser', [$account, $creador_jefe2])
        );
    }

    // =========================================================================
    // super_admin bypass
    // =========================================================================

    public function test_super_admin_puede_asignar_a_cualquier_usuario(): void
    {
        $sa      = $this->makeSuperAdmin();
        $sv      = $this->makeSupervisor('9');
        $jefe    = $this->makeJefe($sv, '8');
        $creador = $this->makeCreador($jefe, '4');
        $account = $this->makeAccount($sv, 'Cuenta SA Test');

        // super_admin puede asignar a cualquiera (Gate::before bypass)
        $this->assertTrue(Gate::forUser($sa)->allows('assignToUser', [$account, $jefe]));
        $this->assertTrue(Gate::forUser($sa)->allows('assignToUser', [$account, $creador]));
    }

    // =========================================================================
    // Creador nunca puede asignar
    // =========================================================================

    public function test_creador_no_puede_asignar_cuentas(): void
    {
        $sv      = $this->makeSupervisor('10');
        $jefe    = $this->makeJefe($sv, '9');
        $creador = $this->makeCreador($jefe, '5');
        $creador2 = $this->makeCreador($jefe, '6');
        $account = $this->makeAccount($sv, 'Cuenta Creador Test');

        // Creador no puede asignar a nadie, ni a sí mismo
        $this->assertFalse(
            Gate::forUser($creador)->allows('assignToUser', [$account, $creador2])
        );
    }

    // =========================================================================
    // assignTo() efectivo + verificación en BD
    // =========================================================================

    public function test_cadena_completa_sv_asigna_jefe_jefe_asigna_creador(): void
    {
        $sv      = $this->makeSupervisor('11');
        $jefe    = $this->makeJefe($sv, '10');
        $creador = $this->makeCreador($jefe, '7');
        $account = $this->makeAccount($sv, 'Cuenta Cadena Completa');

        // Paso 1: supervisora asigna al jefe
        $this->assertTrue(Gate::forUser($sv)->allows('assignToUser', [$account, $jefe]));
        $account->assignTo($jefe, $sv);

        // Verificar pivot sv→jefe
        $this->assertDatabaseHas('account_user', [
            'account_id'  => $account->id,
            'user_id'     => $jefe->id,
            'assigned_by' => $sv->id,
        ]);

        // Paso 2: jefe (con cuenta asignada) asigna al creador
        $this->assertTrue(Gate::forUser($jefe)->allows('assignToUser', [$account, $creador]));
        $account->assignTo($creador, $jefe);

        // Verificar pivot jefe→creador
        $this->assertDatabaseHas('account_user', [
            'account_id'  => $account->id,
            'user_id'     => $creador->id,
            'assigned_by' => $jefe->id,
        ]);
    }
}
