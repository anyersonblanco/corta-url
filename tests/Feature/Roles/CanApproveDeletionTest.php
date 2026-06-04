<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests para User::canApproveDeletion(Link) (Fase 4).
 *
 * Escenarios cubiertos para cada rol:
 *  - super_admin: siempre puede.
 *  - supervisor: puede si el creador del link está en su rama.
 *  - supervisor: puede si la cuenta del link le pertenece (createdAccounts).
 *  - supervisor: NO puede si el link es de otra rama.
 *  - jefe: puede si el creador del link es hijo directo.
 *  - jefe: NO puede si el creador no es su hijo.
 *  - creador: nunca puede.
 */
class CanApproveDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $supervisor;
    private User $jefe;
    private User $creador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
        $this->supervisor = User::factory()->create(['role' => 'supervisor', 'parent_id' => $this->superAdmin->id]);
        $this->jefe       = User::factory()->create(['role' => 'jefe',       'parent_id' => $this->supervisor->id]);
        $this->creador    = User::factory()->create(['role' => 'creador',    'parent_id' => $this->jefe->id]);
    }

    private function makeLink(User $creator, ?Account $account = null): Link
    {
        return Link::create([
            'slug'            => 'lnk-' . uniqid(),
            'destination_url' => 'https://webtilia.com',
            'is_active'       => true,
            'created_by'      => $creator->id,
            'account_id'      => $account?->id,
        ]);
    }

    // =========================================================================
    // super_admin
    // =========================================================================

    public function test_super_admin_puede_aprobar_cualquier_link(): void
    {
        $link = $this->makeLink($this->creador);
        $this->assertTrue($this->superAdmin->canApproveDeletion($link));
    }

    // =========================================================================
    // supervisor — vía rama
    // =========================================================================

    public function test_supervisor_puede_aprobar_link_de_creador_en_su_rama(): void
    {
        $link = $this->makeLink($this->creador);
        $this->assertTrue($this->supervisor->canApproveDeletion($link));
    }

    public function test_supervisor_puede_aprobar_link_de_jefe_en_su_rama(): void
    {
        $link = $this->makeLink($this->jefe);
        $this->assertTrue($this->supervisor->canApproveDeletion($link));
    }

    public function test_supervisor_no_puede_aprobar_link_de_otra_rama(): void
    {
        $otroSupervisor = User::factory()->create(['role' => 'supervisor', 'parent_id' => $this->superAdmin->id]);
        $otroJefe       = User::factory()->create(['role' => 'jefe',       'parent_id' => $otroSupervisor->id]);
        $otroCreador    = User::factory()->create(['role' => 'creador',    'parent_id' => $otroJefe->id]);

        $link = $this->makeLink($otroCreador);
        $this->assertFalse($this->supervisor->canApproveDeletion($link));
    }

    // =========================================================================
    // supervisor — vía cuenta
    // =========================================================================

    public function test_supervisor_puede_aprobar_link_con_su_cuenta_aunque_creador_sea_otro(): void
    {
        // Cuenta perteneciente al supervisor
        $account = Account::create([
            'name'          => 'Cuenta del supervisor',
            'supervisor_id' => $this->supervisor->id,
            'is_active'     => true,
        ]);

        // Link creado por otro creador fuera de la rama, pero con la cuenta del supervisor
        $otroCreador = User::factory()->create(['role' => 'creador']);
        $link = $this->makeLink($otroCreador, $account);

        $this->assertTrue(
            $this->supervisor->canApproveDeletion($link),
            'supervisor puede aprobar porque la cuenta le pertenece'
        );
    }

    // =========================================================================
    // jefe
    // =========================================================================

    public function test_jefe_puede_aprobar_link_de_su_creador_directo(): void
    {
        $link = $this->makeLink($this->creador);
        $this->assertTrue($this->jefe->canApproveDeletion($link));
    }

    public function test_jefe_no_puede_aprobar_link_de_creador_de_otro_jefe(): void
    {
        $otroJefe    = User::factory()->create(['role' => 'jefe',    'parent_id' => $this->supervisor->id]);
        $otroCreador = User::factory()->create(['role' => 'creador', 'parent_id' => $otroJefe->id]);

        $link = $this->makeLink($otroCreador);
        $this->assertFalse($this->jefe->canApproveDeletion($link));
    }

    // =========================================================================
    // creador
    // =========================================================================

    public function test_creador_nunca_puede_aprobar(): void
    {
        $linkPropio = $this->makeLink($this->creador);
        $this->assertFalse($this->creador->canApproveDeletion($linkPropio));
    }
}
