<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para User::branchUserIds() (Fase 3).
 *
 * Verifica que cada rol devuelva exactamente el conjunto de IDs esperado:
 *  - super_admin: todos los usuarios del sistema
 *  - supervisor: él mismo + sus jefes + creadores de sus jefes
 *  - jefe: él mismo + sus creadores directos
 *  - creador: solo él mismo
 *  - anti-ciclo: no se cuelga con parent_id cíclico
 *  - rama aislada: no incluye IDs de otra rama
 */
class UserBranchUserIdsTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSuperAdmin(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'super_admin',
            'email' => "sa{$suffix}@webtilia.com",
        ]);
    }

    private function makeSupervisor(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => "sv{$suffix}@webtilia.com",
        ]);
    }

    private function makeJefe(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "jefe{$suffix}@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makeCreador(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "cr{$suffix}@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    // =========================================================================
    // super_admin ve todos
    // =========================================================================

    public function test_super_admin_branchUserIds_incluye_todos_los_usuarios(): void
    {
        $sa  = $this->makeSuperAdmin('1');
        $sv  = $this->makeSupervisor('1');
        $j   = $this->makeJefe($sv, '1');
        $c   = $this->makeCreador($j, '1');

        $ids = $sa->branchUserIds()->sort()->values()->all();

        $this->assertContains($sa->id, $ids);
        $this->assertContains($sv->id, $ids);
        $this->assertContains($j->id, $ids);
        $this->assertContains($c->id, $ids);
        // Debe contener exactamente todos los users de la DB (4 en este test)
        $this->assertCount(User::count(), $ids);
    }

    // =========================================================================
    // supervisor: él mismo + jefes + creadores de sus jefes
    // =========================================================================

    public function test_supervisor_branchUserIds_incluye_a_si_mismo(): void
    {
        $sv = $this->makeSupervisor('2');

        $ids = $sv->branchUserIds()->all();

        $this->assertContains($sv->id, $ids);
    }

    public function test_supervisor_branchUserIds_incluye_sus_jefes(): void
    {
        $sv = $this->makeSupervisor('3');
        $j1 = $this->makeJefe($sv, '3a');
        $j2 = $this->makeJefe($sv, '3b');

        $ids = $sv->branchUserIds()->all();

        $this->assertContains($j1->id, $ids);
        $this->assertContains($j2->id, $ids);
    }

    public function test_supervisor_branchUserIds_incluye_creadores_de_sus_jefes(): void
    {
        $sv = $this->makeSupervisor('4');
        $j  = $this->makeJefe($sv, '4');
        $c1 = $this->makeCreador($j, '4a');
        $c2 = $this->makeCreador($j, '4b');

        $ids = $sv->branchUserIds()->all();

        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
    }

    public function test_supervisor_branchUserIds_no_incluye_usuarios_de_otra_rama(): void
    {
        $sv1 = $this->makeSupervisor('5a');
        $sv2 = $this->makeSupervisor('5b');
        $j2  = $this->makeJefe($sv2, '5');
        $c2  = $this->makeCreador($j2, '5');

        $ids = $sv1->branchUserIds()->all();

        // sv1 no debe ver la rama de sv2
        $this->assertNotContains($sv2->id, $ids);
        $this->assertNotContains($j2->id, $ids);
        $this->assertNotContains($c2->id, $ids);
    }

    // =========================================================================
    // jefe: él mismo + sus creadores directos
    // =========================================================================

    public function test_jefe_branchUserIds_incluye_a_si_mismo_y_sus_creadores(): void
    {
        $sv = $this->makeSupervisor('6');
        $j  = $this->makeJefe($sv, '6');
        $c1 = $this->makeCreador($j, '6a');
        $c2 = $this->makeCreador($j, '6b');

        $ids = $j->branchUserIds()->all();

        $this->assertContains($j->id, $ids);
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        // No debe incluir al supervisor
        $this->assertNotContains($sv->id, $ids);
    }

    public function test_jefe_branchUserIds_no_incluye_creadores_de_otro_jefe(): void
    {
        $sv = $this->makeSupervisor('7');
        $j1 = $this->makeJefe($sv, '7a');
        $j2 = $this->makeJefe($sv, '7b');
        $c2 = $this->makeCreador($j2, '7');

        $ids = $j1->branchUserIds()->all();

        $this->assertNotContains($c2->id, $ids);
    }

    // =========================================================================
    // creador: solo él mismo
    // =========================================================================

    public function test_creador_branchUserIds_devuelve_solo_su_propio_id(): void
    {
        $sv = $this->makeSupervisor('8');
        $j  = $this->makeJefe($sv, '8');
        $c  = $this->makeCreador($j, '8');

        $ids = $c->branchUserIds()->all();

        $this->assertCount(1, $ids);
        $this->assertContains($c->id, $ids);
        $this->assertNotContains($j->id, $ids);
        $this->assertNotContains($sv->id, $ids);
    }

    // =========================================================================
    // Anti-ciclo: no se cuelga con parent_id cíclico
    // =========================================================================

    public function test_branchUserIds_no_cuelga_con_ciclo_en_parent_id(): void
    {
        $a = User::factory()->create(['role' => 'jefe', 'email' => 'cycle_a@webtilia.com']);
        $b = User::factory()->create(['role' => 'creador', 'email' => 'cycle_b@webtilia.com', 'parent_id' => $a->id]);
        // Ciclo: a → b → a
        $a->update(['parent_id' => $b->id]);
        $a->refresh();

        // No debe colgar; debe devolver algo (incluye al propio $a + $b que es su hijo)
        $ids = $a->branchUserIds()->all();

        // Que no sea infinito — el conteo debe ser finito y pequeño
        $this->assertIsArray($ids);
        $this->assertLessThanOrEqual(User::count(), count($ids));
    }
}
