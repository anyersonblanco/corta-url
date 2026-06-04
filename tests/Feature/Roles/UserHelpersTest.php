<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para los helpers de rol y jerarquía del modelo User (Fase 1).
 *
 * Cubre:
 *  - isSuperAdmin(), isSupervisor(), isJefe(), isCreador() correctos por rol
 *  - supervises() con 1, 2, 3 saltos
 *  - supervises() retorna false con 4+ saltos (fuera del máximo)
 *  - supervises() no cuelga ante ciclo en parent_id (hashset de visitados)
 *  - supervises() retorna false cuando el user no está en la rama
 */
class UserHelpersTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers is*()
    // =========================================================================

    public function test_isSuperAdmin_retorna_true_solo_para_super_admin(): void
    {
        $u = User::factory()->create(['role' => 'super_admin', 'email' => 'sa@webtilia.com']);

        $this->assertTrue($u->isSuperAdmin());
        $this->assertFalse($u->isSupervisor());
        $this->assertFalse($u->isJefe());
        $this->assertFalse($u->isCreador());
    }

    public function test_isSupervisor_retorna_true_solo_para_supervisor(): void
    {
        $u = User::factory()->create(['role' => 'supervisor', 'email' => 'sv@webtilia.com']);

        $this->assertFalse($u->isSuperAdmin());
        $this->assertTrue($u->isSupervisor());
        $this->assertFalse($u->isJefe());
        $this->assertFalse($u->isCreador());
    }

    public function test_isJefe_retorna_true_solo_para_jefe(): void
    {
        $u = User::factory()->create(['role' => 'jefe', 'email' => 'jefe@webtilia.com']);

        $this->assertFalse($u->isSuperAdmin());
        $this->assertFalse($u->isSupervisor());
        $this->assertTrue($u->isJefe());
        $this->assertFalse($u->isCreador());
    }

    public function test_isCreador_retorna_true_solo_para_creador(): void
    {
        $u = User::factory()->create(['role' => 'creador', 'email' => 'cr@webtilia.com']);

        $this->assertFalse($u->isSuperAdmin());
        $this->assertFalse($u->isSupervisor());
        $this->assertFalse($u->isJefe());
        $this->assertTrue($u->isCreador());
    }

    // =========================================================================
    // supervises() — saltos directos
    // =========================================================================

    public function test_supervises_1_salto_directo(): void
    {
        // supervisor → jefe (1 salto)
        $supervisor = User::factory()->create(['role' => 'supervisor', 'email' => 'sv1@webtilia.com']);
        $jefe       = User::factory()->create(['role' => 'jefe', 'email' => 'j1@webtilia.com', 'parent_id' => $supervisor->id]);

        $this->assertTrue($supervisor->supervises($jefe));
    }

    public function test_supervises_2_saltos(): void
    {
        // supervisor → jefe → creador (2 saltos)
        $supervisor = User::factory()->create(['role' => 'supervisor', 'email' => 'sv2@webtilia.com']);
        $jefe       = User::factory()->create(['role' => 'jefe', 'email' => 'j2@webtilia.com', 'parent_id' => $supervisor->id]);
        $creador    = User::factory()->create(['role' => 'creador', 'email' => 'cr2@webtilia.com', 'parent_id' => $jefe->id]);

        $this->assertTrue($supervisor->supervises($creador));
    }

    public function test_supervises_3_saltos(): void
    {
        // super_admin → supervisor → jefe → creador (3 saltos)
        $sa         = User::factory()->create(['role' => 'super_admin', 'email' => 'sa3@webtilia.com']);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'email' => 'sv3@webtilia.com', 'parent_id' => $sa->id]);
        $jefe       = User::factory()->create(['role' => 'jefe', 'email' => 'j3@webtilia.com', 'parent_id' => $supervisor->id]);
        $creador    = User::factory()->create(['role' => 'creador', 'email' => 'cr3@webtilia.com', 'parent_id' => $jefe->id]);

        $this->assertTrue($sa->supervises($creador));
        $this->assertTrue($sa->supervises($jefe));
        $this->assertTrue($sa->supervises($supervisor));
    }

    public function test_supervises_4_saltos_retorna_false(): void
    {
        // Profundidad 4 — fuera del máximo permitido de 3
        // nivel0 → nivel1 → nivel2 → nivel3 → nivel4
        $n0 = User::factory()->create(['role' => 'super_admin', 'email' => 'n0@webtilia.com']);
        $n1 = User::factory()->create(['role' => 'supervisor', 'email' => 'n1@webtilia.com', 'parent_id' => $n0->id]);
        $n2 = User::factory()->create(['role' => 'jefe', 'email' => 'n2@webtilia.com', 'parent_id' => $n1->id]);
        $n3 = User::factory()->create(['role' => 'creador', 'email' => 'n3@webtilia.com', 'parent_id' => $n2->id]);
        // Forzamos un 4º nivel en la BD (rompiendo la restricción de negocio, para probar la defensa)
        $n4 = User::factory()->create(['role' => 'creador', 'email' => 'n4@webtilia.com', 'parent_id' => $n3->id]);

        // n0 no debería "ver" a n4 porque supera los 3 saltos del algoritmo
        $this->assertFalse($n0->supervises($n4));
    }

    // =========================================================================
    // supervises() — protección contra ciclos
    // =========================================================================

    public function test_supervises_no_cuelga_con_parent_id_apuntando_a_si_mismo(): void
    {
        $u = User::factory()->create(['role' => 'creador', 'email' => 'loop1@webtilia.com']);
        // Ciclo: user apunta a sí mismo como padre (inválido, pero lo forzamos en BD)
        $u->update(['parent_id' => $u->id]);
        $u->refresh();

        $otro = User::factory()->create(['role' => 'creador', 'email' => 'loop2@webtilia.com']);

        // No debe colgar — debe retornar false antes de los 3 saltos por ciclo detectado
        $this->assertFalse($otro->supervises($u));
    }

    public function test_supervises_no_cuelga_con_ciclo_entre_dos_usuarios(): void
    {
        // user A → parent = B, user B → parent = A
        $a = User::factory()->create(['role' => 'jefe', 'email' => 'cyclea@webtilia.com']);
        $b = User::factory()->create(['role' => 'jefe', 'email' => 'cycleb@webtilia.com', 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]); // ciclo
        $a->refresh();
        $b->refresh();

        $supervisor = User::factory()->create(['role' => 'supervisor', 'email' => 'svcy@webtilia.com']);

        // supervisor supervisa a ninguno de los dos (están en ciclo, no en su rama)
        $this->assertFalse($supervisor->supervises($a));
        $this->assertFalse($supervisor->supervises($b));
    }

    // =========================================================================
    // supervises() — fuera de la rama
    // =========================================================================

    public function test_supervises_retorna_false_fuera_de_la_rama(): void
    {
        $sv1 = User::factory()->create(['role' => 'supervisor', 'email' => 'sv_a@webtilia.com']);
        $sv2 = User::factory()->create(['role' => 'supervisor', 'email' => 'sv_b@webtilia.com']);
        $jefe = User::factory()->create(['role' => 'jefe', 'email' => 'j_b@webtilia.com', 'parent_id' => $sv2->id]);

        // sv1 NO supervisa al jefe de sv2
        $this->assertFalse($sv1->supervises($jefe));
    }

    public function test_supervises_retorna_false_para_si_mismo(): void
    {
        $u = User::factory()->create(['role' => 'supervisor', 'email' => 'self@webtilia.com']);
        $this->assertFalse($u->supervises($u));
    }

    // =========================================================================
    // creatableRoles()
    // =========================================================================

    public function test_creatable_roles_super_admin_todos(): void
    {
        $sa = User::factory()->create(['role' => 'super_admin', 'email' => 'crsup@webtilia.com']);
        $this->assertSame(['super_admin', 'supervisor', 'jefe', 'creador'], $sa->creatableRoles());
    }

    public function test_creatable_roles_supervisor_solo_jefe(): void
    {
        $sv = User::factory()->create(['role' => 'supervisor', 'email' => 'crsvp@webtilia.com']);
        $this->assertSame(['jefe'], $sv->creatableRoles());
    }

    public function test_creatable_roles_jefe_solo_creador(): void
    {
        $j = User::factory()->create(['role' => 'jefe', 'email' => 'crjefe@webtilia.com']);
        $this->assertSame(['creador'], $j->creatableRoles());
    }

    public function test_creatable_roles_creador_vacio(): void
    {
        $c = User::factory()->create(['role' => 'creador', 'email' => 'crcrea@webtilia.com']);
        $this->assertSame([], $c->creatableRoles());
    }
}
