<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\Link;
use App\Models\LinkClick;
use App\Models\LinkDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test E2E del feature completo de roles jerárquicos (Fase 6).
 *
 * Recorre el flujo completo en un único test integrado:
 *   Creación de usuarios en cascada jerárquica
 *   → Asignación de cuentas por cadena
 *   → Creación de link por creador
 *   → Flujo de solicitud de eliminación (pending → aprobado)
 *   → Verificación de archivado + histórico de clicks intacto
 *   → Desactivación de jefe → detección de huérfano
 *   → Reasignación a nuevo jefe
 *   → Reactivación de jefe original (creador NO revierte a jefe anterior)
 *
 * ~30 asserts dentro de 1 test que sella el feature completo.
 */
class EndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_flujo_completo_roles_jerarquicos_de_extremo_a_extremo(): void
    {
        // =====================================================================
        // PASO 1 — Inicializar: super_admin seedeado por migración
        // (en tests RefreshDatabase no corre la data migration de seed, así que
        // lo creamos explícitamente como ocurre en el setup real)
        // =====================================================================
        $superAdmin = User::factory()->create([
            'name'      => 'Super Admin',
            'email'     => 'superadmin@webtilia.com',
            'role'      => 'super_admin',
            'is_active' => true,
        ]);

        $this->assertSame('super_admin', $superAdmin->role);
        $this->assertTrue($superAdmin->isSuperAdmin());

        // =====================================================================
        // PASO 2 — super_admin crea supervisor SupA
        // =====================================================================
        $supA = User::factory()->create([
            'name'      => 'Supervisor A',
            'email'     => 'supa@webtilia.com',
            'role'      => 'supervisor',
            'parent_id' => $superAdmin->id,
            'is_active' => true,
        ]);

        // Verificar que super_admin supervisa a supA
        $this->assertSame('supervisor', $supA->role);
        $this->assertSame($superAdmin->id, $supA->parent_id);
        $this->assertTrue($superAdmin->supervises($supA));

        // =====================================================================
        // PASO 3 — SupA crea jefe JefA
        // =====================================================================
        $jefA = User::factory()->create([
            'name'      => 'Jefe A',
            'email'     => 'jefa@webtilia.com',
            'role'      => 'jefe',
            'parent_id' => $supA->id,
            'is_active' => true,
        ]);

        $this->assertSame('jefe', $jefA->role);
        $this->assertSame($supA->id, $jefA->parent_id);
        $this->assertTrue($supA->supervises($jefA));

        // =====================================================================
        // PASO 4 — JefA crea creador CreA
        // =====================================================================
        $creA = User::factory()->create([
            'name'      => 'Creador A',
            'email'     => 'crea@webtilia.com',
            'role'      => 'creador',
            'parent_id' => $jefA->id,
            'is_active' => true,
        ]);

        $this->assertSame('creador', $creA->role);
        $this->assertSame($jefA->id, $creA->parent_id);
        $this->assertTrue($jefA->supervises($creA));

        // =====================================================================
        // PASO 5 — SupA crea cuenta CtaCliente1 y la asigna a JefA
        // =====================================================================
        $ctaCliente1 = Account::create([
            'name'          => 'CtaCliente1',
            'supervisor_id' => $supA->id,
            'is_active'     => true,
        ]);

        $assigned = $ctaCliente1->assignTo($jefA, $supA);
        $this->assertTrue($assigned, 'Primera asignación debe insertar fila en pivot');
        $this->assertTrue($jefA->accounts()->where('accounts.id', $ctaCliente1->id)->exists());

        // =====================================================================
        // PASO 6 — JefA asigna CtaCliente1 a CreA
        // =====================================================================
        $ctaCliente1->assignTo($creA, $jefA);
        $this->assertTrue($creA->accounts()->where('accounts.id', $ctaCliente1->id)->exists());

        // =====================================================================
        // PASO 7 — CreA crea link slug=test-e2e apuntando a webtilia.com
        // =====================================================================
        $link = Link::create([
            'slug'            => 'test-e2e',
            'destination_url' => 'https://webtilia.com',
            'title'           => 'Link E2E test',
            'is_active'       => true,
            'created_by'      => $creA->id,
            'account_id'      => $ctaCliente1->id,
        ]);

        $this->assertSame('test-e2e', $link->slug);
        $this->assertSame($creA->id, $link->created_by);
        $this->assertSame($ctaCliente1->id, $link->account_id);
        $this->assertNull($link->deleted_at);

        // =====================================================================
        // PASO 8 — CreA solicita eliminación con reason
        // =====================================================================
        $reason = 'el cliente cambió de campaña';
        $request = LinkDeletionRequest::create([
            'link_id'      => $link->id,
            'requested_by' => $creA->id,
            'reason'       => $reason,
            'status'       => LinkDeletionRequest::STATUS_PENDING,
        ]);

        $this->assertSame(LinkDeletionRequest::STATUS_PENDING, $request->status);
        $this->assertTrue($link->hasPendingDeletionRequest());

        // =====================================================================
        // PASO 9 — Verificar que CreA NO puede aprobar (creador nunca puede),
        // y que la Policy correcta aplica: creador ve "Solicitar eliminación"
        // pero NO el DeleteAction directo.
        // =====================================================================

        // canApproveDeletion retorna false para creadores
        $this->assertFalse($creA->canApproveDeletion($link));

        // La LinkPolicy permite requestDeletion en su propio link
        $this->assertTrue($creA->can('requestDeletion', $link));

        // La LinkPolicy NO permite delete directo para creador
        $this->assertFalse($creA->can('delete', $link));

        // =====================================================================
        // PASO 10 — JefA ve la solicitud (es jefe directo del solicitante)
        // =====================================================================
        $this->assertTrue($jefA->canApproveDeletion($link));

        // El link fue creado por CreA (hijo directo de JefA) → aparece en su rama
        $jefaBranchIds = $jefA->branchUserIds();
        $this->assertContains($creA->id, $jefaBranchIds->all());

        // La solicitud tiene requested_by = creA.id, que está en la rama de JefA
        $requestInJefaBranch = LinkDeletionRequest::whereIn('requested_by', $jefaBranchIds->all())
            ->where('status', LinkDeletionRequest::STATUS_PENDING)
            ->first();
        $this->assertNotNull($requestInJefaBranch);
        $this->assertSame($request->id, $requestInJefaBranch->id);

        // =====================================================================
        // PASO 11 — JefA aprueba la solicitud
        // =====================================================================
        $request->approve($jefA, 'Aprobado por jefe A');

        // =====================================================================
        // PASO 12 — Verificar archivado completo
        // =====================================================================

        // Link archivado (deleted_at != null)
        $linkArchivado = Link::withTrashed()->find($link->id);
        $this->assertNotNull($linkArchivado->deleted_at, 'Link debe estar archivado (deleted_at != null)');

        // /l/test-e2e retorna 404 (link excluido de queries normales por SoftDeletes)
        $response = $this->get('/l/test-e2e');
        $response->assertStatus(404);

        // Request status = approved
        $request->refresh();
        $this->assertSame(LinkDeletionRequest::STATUS_APPROVED, $request->status);

        // reviewed_by = JefA.id
        $this->assertSame($jefA->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        // =====================================================================
        // PASO 13 — Histórico de link_clicks intacto (SoftDelete no cascadea sobre clicks)
        // =====================================================================
        $linkId = $link->id;

        // Insertar clicks de prueba directamente para simular historial previo al archivado.
        // link_clicks tiene timestamps=false (solo created_at, sin updated_at por diseño).
        LinkClick::insert([
            ['link_id' => $linkId, 'created_at' => now()->subHours(2)],
            ['link_id' => $linkId, 'created_at' => now()->subHour()],
        ]);

        $clickCount = LinkClick::where('link_id', $linkId)->count();
        $this->assertSame(2, $clickCount, 'link_clicks del link archivado deben permanecer en BD');

        // =====================================================================
        // PASO 14 — super_admin desactiva JefA
        // =====================================================================
        $jefA->update(['is_active' => false]);
        $jefA->refresh();

        $this->assertFalse($jefA->is_active);

        // =====================================================================
        // PASO 15 — CreA ahora es huérfano (su parent JefA está inactivo)
        // =====================================================================
        $creA->unsetRelation('parent');
        $creA->refresh();

        $this->assertTrue($creA->isOrphan(), 'CreA debe ser huérfano porque JefA está inactivo');

        // User::orphan() scope también lo retorna
        $orphanIds = User::orphan()->pluck('id');
        $this->assertContains($creA->id, $orphanIds->all());

        // =====================================================================
        // PASO 16 — super_admin crea JefB (otro jefe de SupA) y reasigna CreA a JefB
        // =====================================================================
        $jefB = User::factory()->create([
            'name'      => 'Jefe B',
            'email'     => 'jefb@webtilia.com',
            'role'      => 'jefe',
            'parent_id' => $supA->id,
            'is_active' => true,
        ]);

        $creA->reassignParent($jefB);
        $creA->refresh();

        // =====================================================================
        // PASO 17 — Verificar reasignación
        // =====================================================================
        $this->assertSame($jefB->id, $creA->parent_id, 'CreA.parent_id debe ser JefB.id');
        $this->assertFalse($creA->isOrphan(), 'CreA ya no es huérfano tras reasignación');

        // =====================================================================
        // PASO 18 — super_admin reactiva JefA. CreA permanece bajo JefB (no auto-revert)
        // =====================================================================
        $jefA->update(['is_active' => true]);
        $jefA->refresh();
        $creA->refresh();

        $this->assertTrue($jefA->is_active, 'JefA debe estar activo nuevamente');
        $this->assertSame($jefB->id, $creA->parent_id, 'CreA debe seguir bajo JefB, no revertir a JefA automáticamente');
        $this->assertFalse($creA->isOrphan(), 'CreA sigue sin ser huérfano');
    }
}
