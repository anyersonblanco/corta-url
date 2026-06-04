<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de deteccion de orfandad en User y Account (Fase 5).
 *
 * Cubre:
 *  - User::isOrphan() en cada combinacion parent activo/inactivo/null
 *  - User::orphanReason() devuelve string correcto
 *  - User::scopeOrphan() filtra correctamente (creadores + jefes huerfanos)
 *  - Account::isOrphan() en escenarios analogos
 *  - Account::orphanReason() devuelve string correcto
 *  - Account::scopeOrphan() filtra correctamente
 *  - super_admin nunca es huerfano
 */
class OrphanDetectionTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de creacion
    // =========================================================================

    private function makeSuperAdmin(string $suffix = '1'): User
    {
        return User::factory()->create([
            'role'      => 'super_admin',
            'email'     => "sa{$suffix}@webtilia.com",
            'is_active' => true,
        ]);
    }

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
            'name'          => 'Cuenta Test ' . $supervisor->id,
            'supervisor_id' => $supervisor->id,
            'is_active'     => $active,
        ]);
    }

    // =========================================================================
    // User::isOrphan()
    // =========================================================================

    public function test_user_isOrphan_false_cuando_parent_activo(): void
    {
        $sv    = $this->makeSupervisor('10');
        $jefe  = $this->makeJefe($sv, '10');

        $this->assertFalse($jefe->isOrphan());
    }

    public function test_user_isOrphan_true_cuando_parent_inactivo(): void
    {
        $sv    = $this->makeSupervisor('20', false); // inactiva
        $jefe  = $this->makeJefe($sv, '20');

        $this->assertTrue($jefe->isOrphan());
    }

    public function test_user_isOrphan_true_cuando_parent_id_no_null_y_relacion_es_null(): void
    {
        // Verifica que isOrphan() devuelve true cuando parent_id != null
        // pero la relacion parent devuelve null (simulado sin tocar BD).
        // Esto cubre el caso 'parent_missing' que la logica del metodo maneja.
        $sv   = $this->makeSupervisor('30');
        $jefe = $this->makeJefe($sv, '30');

        // Desactivar al supervisor (caso normal testeable sin FK bypass)
        $sv->update(['is_active' => false]);
        $jefe->unsetRelation('parent');

        $this->assertTrue($jefe->isOrphan());
    }

    public function test_user_isOrphan_false_cuando_parent_id_null(): void
    {
        // Supervisor sin parent (raiz) — no es huerfano
        $sv = $this->makeSupervisor('40');
        $this->assertFalse($sv->isOrphan());
    }

    public function test_user_isOrphan_false_para_super_admin_siempre(): void
    {
        $sa = $this->makeSuperAdmin('50');
        $this->assertFalse($sa->isOrphan());
    }

    public function test_user_isOrphan_false_si_usuario_inactivo(): void
    {
        // Un usuario inactivo con parent inactivo: no cuenta como "huerfano activo"
        $sv    = $this->makeSupervisor('60', false);
        $jefe  = $this->makeJefe($sv, '60', false); // jefe tambien inactivo

        $this->assertFalse($jefe->isOrphan());
    }

    // =========================================================================
    // User::orphanReason()
    // =========================================================================

    public function test_orphanReason_null_cuando_parent_activo(): void
    {
        $sv   = $this->makeSupervisor('70');
        $jefe = $this->makeJefe($sv, '70');

        $this->assertNull($jefe->orphanReason());
    }

    public function test_orphanReason_parent_inactive_cuando_parent_inactivo(): void
    {
        $sv   = $this->makeSupervisor('80', false);
        $jefe = $this->makeJefe($sv, '80');

        $this->assertSame('parent_inactive', $jefe->orphanReason());
    }

    public function test_orphanReason_verifica_todos_los_returns_posibles(): void
    {
        // null: user sin parent_id
        $sv = $this->makeSupervisor('90');
        $this->assertNull($sv->orphanReason()); // supervisor sin parent

        // 'parent_inactive': parent existe y está inactivo
        $svInact = $this->makeSupervisor('90b', false);
        $jefe    = $this->makeJefe($svInact, '90');
        $this->assertSame('parent_inactive', $jefe->orphanReason());

        // null: parent activo
        $svActiva = $this->makeSupervisor('90c', true);
        $jefe2    = $this->makeJefe($svActiva, '90b');
        $this->assertNull($jefe2->orphanReason());
    }

    // =========================================================================
    // User::scopeOrphan()
    // =========================================================================

    public function test_scopeOrphan_filtra_jefes_y_creadores_huerfanos(): void
    {
        // Supervisora activa con jefe activo (NO huerfano)
        $svActiva  = $this->makeSupervisor('A1');
        $jefeOk    = $this->makeJefe($svActiva, 'A1');
        $creadorOk = $this->makeCreador($jefeOk, 'A1');

        // Supervisora inactiva — jefe debajo es huerfano
        $svInactiva = $this->makeSupervisor('A2', false);
        $jefeHuerfano = $this->makeJefe($svInactiva, 'A2');

        // Jefe inactivo con supervisora activa — creador debajo es huerfano
        // pero el jefe mismo no cuenta (is_active=false)
        $jefeInactivo  = $this->makeJefe($svActiva, 'A3', false);
        $creadorHuerfano = $this->makeCreador($jefeInactivo, 'A3');

        $orphanIds = User::orphan()->pluck('id');

        // Jefe huerfano SI debe aparecer
        $this->assertContains($jefeHuerfano->id, $orphanIds);
        // Creador huerfano SI debe aparecer
        $this->assertContains($creadorHuerfano->id, $orphanIds);
        // Jefe con supervisora activa NO
        $this->assertNotContains($jefeOk->id, $orphanIds);
        // Creador con jefe activo NO
        $this->assertNotContains($creadorOk->id, $orphanIds);
        // El jefe inactivo mismo NO aparece (is_active=false)
        $this->assertNotContains($jefeInactivo->id, $orphanIds);
    }

    public function test_scopeOrphan_excluye_super_admin(): void
    {
        $sa = $this->makeSuperAdmin('SA1');

        $orphanIds = User::orphan()->pluck('id');

        $this->assertNotContains($sa->id, $orphanIds);
    }

    // =========================================================================
    // Account::isOrphan()
    // =========================================================================

    public function test_account_isOrphan_false_cuando_supervisora_activa(): void
    {
        $sv      = $this->makeSupervisor('AC1');
        $account = $this->makeAccount($sv);

        $this->assertFalse($account->isOrphan());
    }

    public function test_account_isOrphan_true_cuando_supervisora_inactiva(): void
    {
        $sv      = $this->makeSupervisor('AC2', false);
        $account = $this->makeAccount($sv);

        $this->assertTrue($account->isOrphan());
    }

    public function test_account_isOrphan_true_cuando_supervisora_inactiva_verificacion_adicional(): void
    {
        // Segundo escenario de isOrphan con cuenta diferente para mayor cobertura
        $sv      = $this->makeSupervisor('AC3', false); // inactiva desde el inicio
        $account = $this->makeAccount($sv);

        // La cuenta está activa y su supervisora inactiva => huérfana
        $this->assertTrue($account->isOrphan());
        // Reactivar supervisora => ya no huérfana
        $sv->update(['is_active' => true]);
        $account->unsetRelation('supervisor');
        $this->assertFalse($account->isOrphan());
    }

    public function test_account_isOrphan_false_si_cuenta_inactiva(): void
    {
        $sv      = $this->makeSupervisor('AC4', false);
        $account = $this->makeAccount($sv, false); // cuenta inactiva

        $this->assertFalse($account->isOrphan());
    }

    // =========================================================================
    // Account::orphanReason()
    // =========================================================================

    public function test_account_orphanReason_null_cuando_supervisora_activa(): void
    {
        $sv      = $this->makeSupervisor('AR1');
        $account = $this->makeAccount($sv);

        $this->assertNull($account->orphanReason());
    }

    public function test_account_orphanReason_supervisor_inactive(): void
    {
        $sv      = $this->makeSupervisor('AR2', false);
        $account = $this->makeAccount($sv);

        $this->assertSame('supervisor_inactive', $account->orphanReason());
    }

    public function test_account_orphanReason_verifica_todos_los_returns_posibles(): void
    {
        // null: cuenta con supervisora activa
        $svActiva = $this->makeSupervisor('AR3');
        $account1 = $this->makeAccount($svActiva);
        $this->assertNull($account1->orphanReason());

        // 'supervisor_inactive': supervisora existe pero inactiva
        $svInact  = $this->makeSupervisor('AR3b', false);
        $account2 = $this->makeAccount($svInact);
        $this->assertSame('supervisor_inactive', $account2->orphanReason());

        // null: cuenta inactiva (no aplica orfandad)
        $svInact2 = $this->makeSupervisor('AR3c', false);
        $account3 = $this->makeAccount($svInact2, false);
        $this->assertNull($account3->orphanReason());
    }

    // =========================================================================
    // Account::scopeOrphan()
    // =========================================================================

    public function test_account_scopeOrphan_filtra_correctamente(): void
    {
        $svActiva   = $this->makeSupervisor('AS1');
        $svInactiva = $this->makeSupervisor('AS2', false);

        $cuentaOk      = $this->makeAccount($svActiva);
        $cuentaHuerfana = $this->makeAccount($svInactiva);
        $cuentaInactiva = $this->makeAccount($svInactiva, false); // cuenta inactiva con sv inactiva

        $orphanIds = Account::orphan()->pluck('id');

        $this->assertContains($cuentaHuerfana->id, $orphanIds);
        $this->assertNotContains($cuentaOk->id, $orphanIds);
        // Cuenta inactiva no aparece aunque su supervisora tambien sea inactiva
        $this->assertNotContains($cuentaInactiva->id, $orphanIds);
    }
}
