<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de las acciones de reasignacion de huerfanos (Fase 5).
 *
 * Cubre:
 *  - reassignParent: jefe -> supervisor activa OK
 *  - reassignParent: creador -> jefe activo OK
 *  - reassignParent: rol incompatible (jefe -> otro jefe) lanza InvalidArgumentException
 *  - reassignParent: target inactive lanza InvalidArgumentException
 *  - Account::reassignSupervisor OK
 *  - reassignSupervisor: target no es role=supervisor lanza
 *  - reassignSupervisor: target inactive lanza
 *  - User::reassignBranchTo: mueve todos los jefes de A a B, retorna count
 *  - reassignBranchTo: jefes de B no se tocan
 *  - Reactivar supervisora antigua NO revierte automatico las reasignaciones
 */
class ReassignmentActionsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de creacion
    // =========================================================================

    private function makeSupervisor(string $suffix = '1', bool $active = true): User
    {
        return User::factory()->create([
            'role'      => 'supervisor',
            'email'     => "sv{$suffix}@webtilia.com",
            'is_active' => $active,
        ]);
    }

    private function makeJefe(User $supervisor, string $suffix = '1', bool $active = true): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "jefe{$suffix}@webtilia.com",
            'parent_id' => $supervisor->id,
            'is_active' => $active,
        ]);
    }

    private function makeCreador(User $jefe, string $suffix = '1', bool $active = true): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "cr{$suffix}@webtilia.com",
            'parent_id' => $jefe->id,
            'is_active' => $active,
        ]);
    }

    private function makeAccount(User $supervisor, bool $active = true): Account
    {
        return Account::create([
            'name'          => 'Cuenta ' . $supervisor->id . uniqid(),
            'supervisor_id' => $supervisor->id,
            'is_active'     => $active,
        ]);
    }

    // =========================================================================
    // User::reassignParent()
    // =========================================================================

    public function test_reassignParent_jefe_a_supervisor_activa_ok(): void
    {
        $svVieja  = $this->makeSupervisor('RP1', false);
        $svNueva  = $this->makeSupervisor('RP1b');
        $jefe     = $this->makeJefe($svVieja, 'RP1');

        $jefe->reassignParent($svNueva);

        $jefe->refresh();
        $this->assertEquals($svNueva->id, $jefe->parent_id);
        // Ya no es huerfano
        $this->assertFalse($jefe->isOrphan());
    }

    public function test_reassignParent_creador_a_jefe_activo_ok(): void
    {
        $svActiva    = $this->makeSupervisor('RP2');
        $jefeViejo   = $this->makeJefe($svActiva, 'RP2', false);
        $jefeNuevo   = $this->makeJefe($svActiva, 'RP2b');
        $creador     = $this->makeCreador($jefeViejo, 'RP2');

        $creador->reassignParent($jefeNuevo);

        $creador->refresh();
        $this->assertEquals($jefeNuevo->id, $creador->parent_id);
        $this->assertFalse($creador->isOrphan());
    }

    public function test_reassignParent_rol_incompatible_jefe_a_jefe_lanza(): void
    {
        $sv      = $this->makeSupervisor('RP3');
        $jefe    = $this->makeJefe($sv, 'RP3');
        $otroJefe = $this->makeJefe($sv, 'RP3b');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/supervisor/');

        $jefe->reassignParent($otroJefe); // jefe no puede tener jefe como parent
    }

    public function test_reassignParent_target_inactivo_lanza(): void
    {
        $svInactiva = $this->makeSupervisor('RP4', false);
        $sv2        = $this->makeSupervisor('RP4b', false); // destino tambien inactivo
        $jefe       = $this->makeJefe($svInactiva, 'RP4');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inactivo/');

        $jefe->reassignParent($sv2);
    }

    public function test_reassignParent_rol_creador_con_supervisor_lanza(): void
    {
        $sv      = $this->makeSupervisor('RP5');
        $jefe    = $this->makeJefe($sv, 'RP5', false);
        $creador = $this->makeCreador($jefe, 'RP5');

        $this->expectException(\InvalidArgumentException::class);

        $creador->reassignParent($sv); // creador debe ir bajo jefe, no supervisor
    }

    // =========================================================================
    // Account::reassignSupervisor()
    // =========================================================================

    public function test_reassignSupervisor_ok_y_queda_en_nueva_supervisora(): void
    {
        $svVieja  = $this->makeSupervisor('AS1', false);
        $svNueva  = $this->makeSupervisor('AS1b');
        $account  = $this->makeAccount($svVieja);

        $account->reassignSupervisor($svNueva);

        $account->refresh();
        $this->assertEquals($svNueva->id, $account->supervisor_id);
        $this->assertFalse($account->isOrphan());
    }

    public function test_reassignSupervisor_target_no_es_supervisor_lanza(): void
    {
        $sv      = $this->makeSupervisor('AS2', false);
        $jefe    = $this->makeJefe($sv, 'AS2'); // jefe, no supervisor
        $account = $this->makeAccount($sv);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/supervisor/");

        $account->reassignSupervisor($jefe);
    }

    public function test_reassignSupervisor_target_inactivo_lanza(): void
    {
        $svVieja  = $this->makeSupervisor('AS3', false);
        $svInact  = $this->makeSupervisor('AS3b', false); // destino inactivo
        $account  = $this->makeAccount($svVieja);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inactiva/');

        $account->reassignSupervisor($svInact);
    }

    // =========================================================================
    // User::reassignBranchTo()
    // =========================================================================

    public function test_reassignBranchTo_mueve_todos_los_jefes_y_retorna_count(): void
    {
        $svVieja = $this->makeSupervisor('RB1', false);
        $svNueva = $this->makeSupervisor('RB1b');

        $jefe1 = $this->makeJefe($svVieja, 'RB1a');
        $jefe2 = $this->makeJefe($svVieja, 'RB1b');
        $jefe3 = $this->makeJefe($svVieja, 'RB1c');

        $count = User::reassignBranchTo($svVieja, $svNueva);

        $this->assertEquals(3, $count);

        // Todos quedan bajo la nueva supervisora
        foreach ([$jefe1, $jefe2, $jefe3] as $jefe) {
            $jefe->refresh();
            $this->assertEquals($svNueva->id, $jefe->parent_id);
        }
    }

    public function test_reassignBranchTo_jefes_de_otra_supervisora_no_se_tocan(): void
    {
        $svVieja = $this->makeSupervisor('RB2', false);
        $svOtra  = $this->makeSupervisor('RB2b');
        $svNueva = $this->makeSupervisor('RB2c');

        $jefeViejaRama = $this->makeJefe($svVieja, 'RB2a');
        $jefeOtraRama  = $this->makeJefe($svOtra, 'RB2b');

        User::reassignBranchTo($svVieja, $svNueva);

        // El jefe de la otra supervisora NO debe moverse
        $jefeOtraRama->refresh();
        $this->assertEquals($svOtra->id, $jefeOtraRama->parent_id);

        // El jefe de la vieja SÍ se movio
        $jefeViejaRama->refresh();
        $this->assertEquals($svNueva->id, $jefeViejaRama->parent_id);
    }

    public function test_reassignBranchTo_retorna_cero_si_no_hay_jefes(): void
    {
        $svVieja = $this->makeSupervisor('RB3', false);
        $svNueva = $this->makeSupervisor('RB3b');

        $count = User::reassignBranchTo($svVieja, $svNueva);

        $this->assertEquals(0, $count);
    }

    public function test_reassignBranchTo_destino_no_supervisor_lanza(): void
    {
        $svVieja = $this->makeSupervisor('RB4', false);
        $jefe    = $this->makeJefe($svVieja, 'RB4'); // jefe como destino (invalido)

        $this->expectException(\InvalidArgumentException::class);

        User::reassignBranchTo($svVieja, $jefe);
    }

    public function test_reassignBranchTo_destino_inactivo_lanza(): void
    {
        $svVieja = $this->makeSupervisor('RB5', false);
        $svInact = $this->makeSupervisor('RB5b', false); // destino inactivo

        $this->expectException(\InvalidArgumentException::class);

        User::reassignBranchTo($svVieja, $svInact);
    }

    // =========================================================================
    // Reactivar supervisora antigua NO revierte las reasignaciones
    // =========================================================================

    public function test_reactivar_supervisora_antigua_no_revierte_reasignaciones(): void
    {
        $svVieja = $this->makeSupervisor('REV1', false);
        $svNueva = $this->makeSupervisor('REV1b');
        $jefe    = $this->makeJefe($svVieja, 'REV1');

        // Reasignar el jefe a la nueva supervisora
        $jefe->reassignParent($svNueva);

        // Reactivar la supervisora vieja
        $svVieja->update(['is_active' => true]);

        // El jefe sigue bajo la nueva supervisora — no se revirtio
        $jefe->refresh();
        $this->assertEquals($svNueva->id, $jefe->parent_id);
        $this->assertFalse($jefe->isOrphan());
    }
}
