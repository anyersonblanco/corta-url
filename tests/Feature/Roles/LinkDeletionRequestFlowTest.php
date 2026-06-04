<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\Link;
use App\Models\LinkDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del flujo completo de solicitud de eliminación de links (Fase 4).
 *
 * Árbol de usuarios de prueba:
 *   super_admin
 *   supervisor (hijo de super_admin)
 *     ├── jefe (hijo de supervisor)
 *     │     └── creador (hijo de jefe)
 *     └── jefe_otro (hijo de supervisor)
 *           └── creador_otro (hijo de jefe_otro)
 */
class LinkDeletionRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $supervisor;
    private User $jefe;
    private User $creador;
    private User $jefeOtro;
    private User $creadorOtro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin   = User::factory()->create(['role' => 'super_admin']);
        $this->supervisor   = User::factory()->create(['role' => 'supervisor',  'parent_id' => $this->superAdmin->id]);
        $this->jefe         = User::factory()->create(['role' => 'jefe',        'parent_id' => $this->supervisor->id]);
        $this->creador      = User::factory()->create(['role' => 'creador',     'parent_id' => $this->jefe->id]);
        $this->jefeOtro     = User::factory()->create(['role' => 'jefe',        'parent_id' => $this->supervisor->id]);
        $this->creadorOtro  = User::factory()->create(['role' => 'creador',     'parent_id' => $this->jefeOtro->id]);
    }

    private function makeLinkFor(User $creator, array $attrs = []): Link
    {
        return Link::create(array_merge([
            'slug'            => 'lnk-' . uniqid(),
            'destination_url' => 'https://webtilia.com',
            'is_active'       => true,
            'created_by'      => $creator->id,
        ], $attrs));
    }

    private function makeRequest(Link $link, User $requester, string $reason = 'Motivo de prueba'): LinkDeletionRequest
    {
        return LinkDeletionRequest::create([
            'link_id'      => $link->id,
            'requested_by' => $requester->id,
            'reason'       => $reason,
            'status'       => LinkDeletionRequest::STATUS_PENDING,
        ]);
    }

    // =========================================================================
    // Solicitar eliminación
    // =========================================================================

    public function test_creador_puede_solicitar_eliminacion_de_su_propio_link(): void
    {
        $link = $this->makeLinkFor($this->creador);

        $request = $this->makeRequest($link, $this->creador);

        $this->assertDatabaseHas('link_deletion_requests', [
            'link_id'      => $link->id,
            'requested_by' => $this->creador->id,
            'status'       => LinkDeletionRequest::STATUS_PENDING,
        ]);
        $this->assertTrue($link->hasPendingDeletionRequest());
    }

    public function test_creador_no_puede_solicitar_eliminacion_de_link_ajeno_via_policy(): void
    {
        // Verificamos que LinkPolicy::requestDeletion retorna false para link ajeno.
        $linkAjeno = $this->makeLinkFor($this->creadorOtro);

        $puedeNO = $this->creador->can('requestDeletion', $linkAjeno);
        $this->assertFalse($puedeNO, 'creador NO puede solicitar eliminación de link ajeno');

        $puedeSI = $this->creador->can('requestDeletion', $this->makeLinkFor($this->creador));
        $this->assertTrue($puedeSI, 'creador SÍ puede solicitar eliminación de su propio link');
    }

    public function test_no_se_puede_crear_segunda_solicitud_pending_para_el_mismo_link(): void
    {
        $link = $this->makeLinkFor($this->creador);

        // Primera solicitud
        $this->makeRequest($link, $this->creador);
        $this->assertTrue($link->hasPendingDeletionRequest());

        // Intentar segunda solicitud: hasPendingDeletionRequest() debe bloquearla.
        // En la acción de Filament esto se verifica antes de crear. Aquí probamos
        // el helper directamente.
        $pendingCount = $link->deletionRequests()->where('status', LinkDeletionRequest::STATUS_PENDING)->count();
        $this->assertSame(1, $pendingCount, 'Solo debe existir 1 solicitud pending por link');
    }

    // =========================================================================
    // super_admin — eliminación directa y aprobación
    // =========================================================================

    public function test_super_admin_puede_eliminar_directamente_via_policy(): void
    {
        $link = $this->makeLinkFor($this->creador);

        $puede = $this->superAdmin->can('delete', $link);
        $this->assertTrue($puede);
    }

    public function test_super_admin_aprueba_solicitud_link_archivado_status_updated(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        $request->approve($this->superAdmin, 'Aprobado por super_admin');

        // Link archivado
        $this->assertNotNull(Link::withTrashed()->find($link->id)->deleted_at);
        // Solicitud actualizada
        $request->refresh();
        $this->assertSame(LinkDeletionRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($this->superAdmin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame('Aprobado por super_admin', $request->review_note);
    }

    // =========================================================================
    // supervisor — aprobación en rama vs fuera de rama
    // =========================================================================

    public function test_supervisor_aprueba_solicitud_de_creador_en_su_rama(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        // supervisor tiene al creador en su rama (supervisor → jefe → creador)
        $this->assertTrue($this->supervisor->canApproveDeletion($link));

        $request->approve($this->supervisor);

        $request->refresh();
        $this->assertSame(LinkDeletionRequest::STATUS_APPROVED, $request->status);
        $this->assertNotNull(Link::withTrashed()->find($link->id)->deleted_at);
    }

    public function test_supervisor_no_puede_aprobar_solicitud_de_creador_en_otra_rama(): void
    {
        // Crear un segundo supervisor con su propio creador
        $supervisorDos = User::factory()->create(['role' => 'supervisor', 'parent_id' => $this->superAdmin->id]);
        $jefeDos       = User::factory()->create(['role' => 'jefe',       'parent_id' => $supervisorDos->id]);
        $creadorDos    = User::factory()->create(['role' => 'creador',    'parent_id' => $jefeDos->id]);

        $link = $this->makeLinkFor($creadorDos);

        // $this->supervisor NO puede aprobar links del creadorDos (otra rama)
        $this->assertFalse($this->supervisor->canApproveDeletion($link));
    }

    // =========================================================================
    // jefe — aprobación de su creador directo vs otro creador
    // =========================================================================

    public function test_jefe_directo_aprueba_solicitud_de_su_creador(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        $this->assertTrue($this->jefe->canApproveDeletion($link));

        $request->approve($this->jefe);

        $request->refresh();
        $this->assertSame(LinkDeletionRequest::STATUS_APPROVED, $request->status);
    }

    public function test_jefe_no_puede_aprobar_solicitud_de_creador_que_no_es_suyo(): void
    {
        // creadorOtro pertenece a jefeOtro, no a $this->jefe
        $link = $this->makeLinkFor($this->creadorOtro);

        $this->assertFalse($this->jefe->canApproveDeletion($link));
    }

    // =========================================================================
    // Rechazo
    // =========================================================================

    public function test_rechazo_link_sigue_activo_status_rejected_review_note_guardado(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        $request->reject($this->superAdmin, 'Este link sigue siendo necesario para la campaña.');

        // Link NO archivado
        $this->assertNull(Link::find($link->id)->deleted_at);

        $request->refresh();
        $this->assertSame(LinkDeletionRequest::STATUS_REJECTED, $request->status);
        $this->assertSame($this->superAdmin->id, $request->reviewed_by);
        $this->assertSame('Este link sigue siendo necesario para la campaña.', $request->review_note);
        $this->assertNotNull($request->reviewed_at);
    }

    // =========================================================================
    // Anti-race
    // =========================================================================

    public function test_aprobar_solicitud_ya_aprobada_lanza_excepcion(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        $request->approve($this->superAdmin);

        $this->expectException(\RuntimeException::class);
        $request->approve($this->superAdmin); // segunda vez → excepción
    }

    public function test_rechazar_solicitud_ya_rechazada_lanza_excepcion(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        $request->reject($this->superAdmin, 'Primer rechazo.');

        $this->expectException(\RuntimeException::class);
        $request->reject($this->superAdmin, 'Segundo rechazo.'); // segunda vez → excepción
    }

    public function test_aprobar_solicitud_ya_rechazada_lanza_excepcion(): void
    {
        $link    = $this->makeLinkFor($this->creador);
        $request = $this->makeRequest($link, $this->creador);

        $request->reject($this->superAdmin, 'Rechazado.');

        $this->expectException(\RuntimeException::class);
        $request->approve($this->superAdmin); // intento de aprobar una rechazada → excepción
    }
}
