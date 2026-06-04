<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del modelo Account y sus helpers (Fase 2).
 *
 * Cubre:
 *  - assignTo() idempotente (segunda llamada retorna false)
 *  - unassignFrom() elimina la fila pivot
 *  - scopeActive() filtra correctamente
 *  - relación users() con pivot assigned_by
 *  - relación supervisor() BelongsTo User
 *  - relación links() HasMany
 */
class AccountModelTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers de creación
    // =========================================================================

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
    // assignTo() — idempotencia
    // =========================================================================

    public function test_assignTo_inserta_primera_vez_y_retorna_true(): void
    {
        $sv      = $this->makeSupervisor();
        $jefe    = $this->makeJefe($sv);
        $account = $this->makeAccount($sv);

        $result = $account->assignTo($jefe, $sv);

        $this->assertTrue($result);
        $this->assertDatabaseHas('account_user', [
            'account_id'  => $account->id,
            'user_id'     => $jefe->id,
            'assigned_by' => $sv->id,
        ]);
    }

    public function test_assignTo_segunda_vez_retorna_false_sin_duplicar(): void
    {
        $sv      = $this->makeSupervisor('2');
        $jefe    = $this->makeJefe($sv, '2');
        $account = $this->makeAccount($sv, 'Cliente Idempotente');

        $account->assignTo($jefe, $sv); // primera vez
        $result = $account->assignTo($jefe, $sv); // segunda vez

        $this->assertFalse($result);

        // Solo una fila en la pivot
        $this->assertEquals(
            1,
            \DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $jefe->id)
                ->count()
        );
    }

    public function test_assignTo_sin_assignedBy_guarda_null_en_pivot(): void
    {
        $sv      = $this->makeSupervisor('3');
        $jefe    = $this->makeJefe($sv, '3');
        $account = $this->makeAccount($sv, 'Cliente sin asignador');

        $account->assignTo($jefe); // sin $assignedBy

        $this->assertDatabaseHas('account_user', [
            'account_id'  => $account->id,
            'user_id'     => $jefe->id,
            'assigned_by' => null,
        ]);
    }

    // =========================================================================
    // unassignFrom()
    // =========================================================================

    public function test_unassignFrom_borra_la_fila_pivot(): void
    {
        $sv      = $this->makeSupervisor('4');
        $jefe    = $this->makeJefe($sv, '4');
        $account = $this->makeAccount($sv, 'Cliente Unassign');

        $account->assignTo($jefe, $sv);
        $this->assertDatabaseHas('account_user', [
            'account_id' => $account->id,
            'user_id'    => $jefe->id,
        ]);

        $account->unassignFrom($jefe);

        $this->assertDatabaseMissing('account_user', [
            'account_id' => $account->id,
            'user_id'    => $jefe->id,
        ]);
    }

    public function test_unassignFrom_cuando_no_existe_no_lanza_excepcion(): void
    {
        $sv      = $this->makeSupervisor('5');
        $jefe    = $this->makeJefe($sv, '5');
        $account = $this->makeAccount($sv, 'Cliente No Asignado');

        // No hay fila pivot — detach no debe explotar
        $account->unassignFrom($jefe);

        $this->assertDatabaseMissing('account_user', [
            'account_id' => $account->id,
            'user_id'    => $jefe->id,
        ]);
    }

    // =========================================================================
    // scopeActive()
    // =========================================================================

    public function test_scope_active_retorna_solo_activas(): void
    {
        $sv = $this->makeSupervisor('6');

        $activa    = $this->makeAccount($sv, 'Activa');
        $inactiva  = Account::create([
            'name'          => 'Inactiva',
            'supervisor_id' => $sv->id,
            'is_active'     => false,
        ]);

        $result = Account::active()->pluck('id');

        $this->assertContains($activa->id, $result);
        $this->assertNotContains($inactiva->id, $result);
    }

    // =========================================================================
    // Relación users() con pivot
    // =========================================================================

    public function test_relacion_users_incluye_pivot_assigned_by(): void
    {
        $sv      = $this->makeSupervisor('7');
        $jefe    = $this->makeJefe($sv, '7');
        $account = $this->makeAccount($sv, 'Cliente Pivot');

        $account->assignTo($jefe, $sv);

        $account->refresh();
        $users = $account->users()->get();

        $this->assertCount(1, $users);
        $this->assertEquals($jefe->id, $users->first()->id);
        $this->assertEquals($sv->id, $users->first()->pivot->assigned_by);
    }

    // =========================================================================
    // Relación supervisor() BelongsTo
    // =========================================================================

    public function test_relacion_supervisor_resuelve_correctamente(): void
    {
        $sv      = $this->makeSupervisor('8');
        $account = $this->makeAccount($sv, 'Cliente Supervisor');

        $this->assertEquals($sv->id, $account->supervisor->id);
        $this->assertEquals($sv->email, $account->supervisor->email);
    }

    // =========================================================================
    // Relación links() HasMany
    // =========================================================================

    public function test_relacion_links_hasMany(): void
    {
        $sv      = $this->makeSupervisor('9');
        $account = $this->makeAccount($sv, 'Cliente Links');

        // Crear links asociados a la cuenta
        \App\Models\Link::factory()->count(3)->create(['account_id' => $account->id]);
        // Crear un link sin cuenta (no debe aparecer)
        \App\Models\Link::factory()->create(['account_id' => null]);

        $this->assertCount(3, $account->links);
    }
}
