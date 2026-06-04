<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de cascada en Account::unassignFrom() (Fase 3).
 *
 * Cuando se retira una cuenta a un jefe, todos los creadores que recibieron
 * esa cuenta de la mano de ese jefe (assigned_by = jefe.id) también pierden la cuenta.
 *
 * Los links existentes NO se borran ni cambian account_id.
 */
class CascadeUnassignTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSuperAdmin(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'super_admin',
            'email' => "sa{$suffix}_cu@webtilia.com",
        ]);
    }

    private function makeSupervisor(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => "sv{$suffix}_cu@webtilia.com",
        ]);
    }

    private function makeJefe(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "j{$suffix}_cu@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makeCreador(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "c{$suffix}_cu@webtilia.com",
            'parent_id' => $parent->id,
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
    // Cascada al retirar cuenta a un jefe
    // =========================================================================

    public function test_retirar_cuenta_a_jefe_elimina_asignaciones_de_sus_creadores(): void
    {
        $sv      = $this->makeSupervisor('1');
        $jefe    = $this->makeJefe($sv, '1');
        $c1      = $this->makeCreador($jefe, '1a');
        $c2      = $this->makeCreador($jefe, '1b');
        $account = $this->makeAccount($sv, 'Cuenta Cascada');

        // sv asigna cuenta al jefe
        $account->assignTo($jefe, $sv);
        // jefe asigna cuenta a sus creadores (assigned_by = jefe)
        $account->assignTo($c1, $jefe);
        $account->assignTo($c2, $jefe);

        // Verificar que las 3 filas existen
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $jefe->id]);
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $c1->id]);
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $c2->id]);

        // sv retira la cuenta al jefe → debe cascadear a c1 y c2
        $account->unassignFrom($jefe);

        // El jefe ya no tiene la cuenta
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $jefe->id]);
        // Los creadores tampoco (cascada)
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $c1->id]);
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $c2->id]);
    }

    public function test_cascada_solo_borra_asignaciones_del_jefe_retirado_no_de_otros(): void
    {
        $sv      = $this->makeSupervisor('2');
        $j1      = $this->makeJefe($sv, '2a');
        $j2      = $this->makeJefe($sv, '2b');
        $c1      = $this->makeCreador($j1, '2a');
        $c2      = $this->makeCreador($j2, '2b');
        $account = $this->makeAccount($sv, 'Cuenta Multi-Jefe');

        // Ambos jefes tienen la cuenta y asignaron a sus creadores
        $account->assignTo($j1, $sv);
        $account->assignTo($j2, $sv);
        $account->assignTo($c1, $j1);  // asignado por j1
        $account->assignTo($c2, $j2);  // asignado por j2

        // Retirar solo a j1 → solo c1 debe perder la cuenta (la de j2 se mantiene)
        $account->unassignFrom($j1);

        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $j1->id]);
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $c1->id]);

        // j2 y c2 siguen teniendo la cuenta intacta
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $j2->id]);
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $c2->id]);
    }

    // =========================================================================
    // Retirar cuenta a un creador (no jefe) NO cascadea
    // =========================================================================

    public function test_retirar_cuenta_a_creador_no_cascadea(): void
    {
        $sv      = $this->makeSupervisor('3');
        $jefe    = $this->makeJefe($sv, '3');
        $c1      = $this->makeCreador($jefe, '3a');
        $c2      = $this->makeCreador($jefe, '3b');
        $account = $this->makeAccount($sv, 'Cuenta Creador');

        $account->assignTo($jefe, $sv);
        $account->assignTo($c1, $jefe);
        $account->assignTo($c2, $jefe);

        // Retirar cuenta a c1 (creador) — solo debe afectar a c1
        $account->unassignFrom($c1);

        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $c1->id]);
        // jefe y c2 siguen teniendo la cuenta
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $jefe->id]);
        $this->assertDatabaseHas('account_user', ['account_id' => $account->id, 'user_id' => $c2->id]);
    }

    // =========================================================================
    // super_admin retira cuenta a jefe → cascada igual
    // =========================================================================

    public function test_super_admin_retira_cuenta_a_jefe_cascadea_a_creadores(): void
    {
        $sa      = $this->makeSuperAdmin('4');
        $sv      = $this->makeSupervisor('4');
        $jefe    = $this->makeJefe($sv, '4');
        $c       = $this->makeCreador($jefe, '4');
        $account = $this->makeAccount($sv, 'Cuenta SA Test');

        $account->assignTo($jefe, $sv);
        $account->assignTo($c, $jefe);

        // super_admin retira la cuenta al jefe
        $account->unassignFrom($jefe);

        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $jefe->id]);
        // El creador también pierde la cuenta (cascada)
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $c->id]);
    }

    // =========================================================================
    // Links existentes NO se borran al hacer cascada
    // =========================================================================

    public function test_links_existentes_no_se_borran_al_hacer_cascada(): void
    {
        $sv      = $this->makeSupervisor('5');
        $jefe    = $this->makeJefe($sv, '5');
        $c       = $this->makeCreador($jefe, '5');
        $account = $this->makeAccount($sv, 'Cuenta Links Cascada');

        $account->assignTo($jefe, $sv);
        $account->assignTo($c, $jefe);

        // Crear links asociados a la cuenta
        $linkJefe   = Link::factory()->create(['account_id' => $account->id, 'created_by' => $jefe->id]);
        $linkCreador = Link::factory()->create(['account_id' => $account->id, 'created_by' => $c->id]);

        // Retirar cuenta al jefe (cascada borra pivot de c)
        $account->unassignFrom($jefe);

        // Las filas pivot desaparecen...
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $jefe->id]);
        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $c->id]);

        // ...pero los links siguen existiendo con su account_id original
        $this->assertDatabaseHas('links', [
            'id'         => $linkJefe->id,
            'account_id' => $account->id,
        ]);
        $this->assertDatabaseHas('links', [
            'id'         => $linkCreador->id,
            'account_id' => $account->id,
        ]);
    }

    // =========================================================================
    // Retirar cuenta a jefe sin creadores asignados por él no lanza excepción
    // =========================================================================

    public function test_retirar_cuenta_a_jefe_sin_creadores_no_lanza_excepcion(): void
    {
        $sv      = $this->makeSupervisor('6');
        $jefe    = $this->makeJefe($sv, '6');
        $account = $this->makeAccount($sv, 'Cuenta Sin Creadores');

        $account->assignTo($jefe, $sv);
        // No se asignan creadores

        // Retirar cuenta al jefe sin creadores — no debe lanzar excepción
        $account->unassignFrom($jefe);

        $this->assertDatabaseMissing('account_user', ['account_id' => $account->id, 'user_id' => $jefe->id]);
    }
}
