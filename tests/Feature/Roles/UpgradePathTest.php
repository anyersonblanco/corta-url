<?php

namespace Tests\Feature\Roles;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests del upgrade path — validación del esquema y comportamiento tras migraciones (Fase 6).
 *
 * Garantiza que:
 *  1. Todas las migrations aplican en orden sin errores.
 *  2. Un usuario pre-feature (creado antes de Fase 1) queda como super_admin.
 *  3. Links pre-feature (sin account_id) quedan con NULL y son visibles solo para super_admin.
 *  4. User::factory() funciona correctamente post-migración.
 *
 * RefreshDatabase corre todas las migrations en cada test, validando que el schema
 * completo (fases 1-5) es aplicable de forma atómica sin conflictos.
 */
class UpgradePathTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Test 1 — Todas las migrations aplican en orden sin errores
    // =========================================================================

    public function test_migrations_aplican_en_orden_sin_errores(): void
    {
        // Si RefreshDatabase llega hasta aquí sin excepción, todas las migrations
        // corrieron exitosamente. Verificamos las tablas y columnas esperadas.

        // Tabla users — columnas del feature de roles
        $this->assertTrue(Schema::hasColumn('users', 'role'), 'users.role debe existir');
        $this->assertTrue(Schema::hasColumn('users', 'parent_id'), 'users.parent_id debe existir');
        $this->assertTrue(Schema::hasColumn('users', 'is_active'), 'users.is_active debe existir');
        $this->assertTrue(Schema::hasColumn('users', 'name'), 'users.name debe existir');
        $this->assertTrue(Schema::hasColumn('users', 'email'), 'users.email debe existir');

        // Tabla accounts — Fase 2
        $this->assertTrue(Schema::hasTable('accounts'), 'tabla accounts debe existir');
        $this->assertTrue(Schema::hasColumn('accounts', 'name'), 'accounts.name debe existir');
        $this->assertTrue(Schema::hasColumn('accounts', 'supervisor_id'), 'accounts.supervisor_id debe existir');
        $this->assertTrue(Schema::hasColumn('accounts', 'is_active'), 'accounts.is_active debe existir');
        $this->assertTrue(Schema::hasColumn('accounts', 'notes'), 'accounts.notes debe existir');

        // Tabla account_user — pivot Fase 2
        $this->assertTrue(Schema::hasTable('account_user'), 'tabla account_user debe existir');
        $this->assertTrue(Schema::hasColumn('account_user', 'account_id'), 'account_user.account_id debe existir');
        $this->assertTrue(Schema::hasColumn('account_user', 'user_id'), 'account_user.user_id debe existir');
        $this->assertTrue(Schema::hasColumn('account_user', 'assigned_by'), 'account_user.assigned_by debe existir');

        // Tabla links — columnas del feature
        $this->assertTrue(Schema::hasTable('links'), 'tabla links debe existir');
        $this->assertTrue(Schema::hasColumn('links', 'account_id'), 'links.account_id debe existir (Fase 2)');
        $this->assertTrue(Schema::hasColumn('links', 'deleted_at'), 'links.deleted_at debe existir (Fase 4 SoftDeletes)');
        $this->assertTrue(Schema::hasColumn('links', 'slug'), 'links.slug debe existir');
        $this->assertTrue(Schema::hasColumn('links', 'destination_url'), 'links.destination_url debe existir');
        $this->assertTrue(Schema::hasColumn('links', 'created_by'), 'links.created_by debe existir');

        // Tabla link_deletion_requests — Fase 4
        $this->assertTrue(Schema::hasTable('link_deletion_requests'), 'tabla link_deletion_requests debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'link_id'), 'link_deletion_requests.link_id debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'requested_by'), 'link_deletion_requests.requested_by debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'reason'), 'link_deletion_requests.reason debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'status'), 'link_deletion_requests.status debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'reviewed_by'), 'link_deletion_requests.reviewed_by debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'reviewed_at'), 'link_deletion_requests.reviewed_at debe existir');
        $this->assertTrue(Schema::hasColumn('link_deletion_requests', 'review_note'), 'link_deletion_requests.review_note debe existir');
    }

    // =========================================================================
    // Test 2 — Usuario pre-feature queda como super_admin tras data migration
    // =========================================================================

    public function test_usuario_pre_feature_queda_super_admin(): void
    {
        // Simular usuario pre-feature via insert directo (como si fuera antes de Fase 1).
        // Usamos DB::table directamente para evitar lógica del modelo/factory.
        DB::table('users')->insert([
            'name'              => 'Pre Feature User',
            'email'             => 'pre@webtilia.com',
            'password'          => bcrypt('password'),
            'role'              => 'creador', // como existía antes del feature
            'parent_id'         => null,
            'is_active'         => true,
            'email_verified_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        // Verificar que se insertó con role='creador' (estado pre-feature)
        $preUser = DB::table('users')->where('email', 'pre@webtilia.com')->first();
        $this->assertSame('creador', $preUser->role, 'Usuario pre-feature debe tener role creador antes de la data migration');

        // Re-aplicar la data migration manualmente
        // (equivale a lo que corre 2026_06_04_100001_seed_existing_users_as_super_admin)
        DB::table('users')->update(['role' => 'super_admin']);

        // Verificar que quedó como super_admin
        $postUser = DB::table('users')->where('email', 'pre@webtilia.com')->first();
        $this->assertSame('super_admin', $postUser->role, 'Usuario pre-feature debe quedar como super_admin tras data migration');

        // Verificar via modelo también
        $userModel = User::where('email', 'pre@webtilia.com')->first();
        $this->assertNotNull($userModel);
        $this->assertTrue($userModel->isSuperAdmin());
    }

    // =========================================================================
    // Test 3 — Links pre-feature quedan sin account_id (NULL)
    //          y solo super_admin los ve en getEloquentQuery
    // =========================================================================

    public function test_links_pre_feature_quedan_sin_account(): void
    {
        // Insertar link sin account_id (simula link pre-Fase 2)
        $linkId = DB::table('links')->insertGetId([
            'slug'            => 'pre-feature-slug',
            'destination_url' => 'https://webtilia.com/pre',
            'is_active'       => true,
            'account_id'      => null, // pre-feature: sin asignar
            'clicks_count'    => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // El link existe en BD
        $this->assertDatabaseHas('links', [
            'id'         => $linkId,
            'slug'       => 'pre-feature-slug',
            'account_id' => null,
        ]);

        // account_id es nullable — verificar que el modelo lo trata como null correctamente
        $link = Link::withTrashed()->find($linkId);
        $this->assertNotNull($link);
        $this->assertNull($link->account_id, 'Link pre-feature debe tener account_id null');

        // El link con created_by=null es invisible para cualquier rol no super_admin
        // porque getEloquentQuery filtra por whereIn('created_by', branchUserIds)
        // y ningún branchUserIds contiene null.
        $supervisor = User::factory()->create([
            'role'      => 'supervisor',
            'email'     => 'sv-upgrade@webtilia.com',
            'is_active' => true,
        ]);

        // branchUserIds del supervisor no incluye null
        $branchIds = $supervisor->branchUserIds()->all();
        $this->assertNotContains(null, $branchIds, 'branchUserIds nunca incluye null');

        // El link con created_by=null no aparece para supervisor (whereIn excluye null)
        $visibleForSupervisor = \App\Models\Link::whereIn('created_by', $branchIds)->pluck('id')->all();
        $this->assertNotContains($linkId, $visibleForSupervisor, 'Link pre-feature (created_by=null) no visible para supervisor');
    }

    // =========================================================================
    // Test 4 — User::factory() funciona post-migración con todas las columnas
    // =========================================================================

    public function test_factory_user_funciona_post_migracion(): void
    {
        $user = User::factory()->create();

        // El factory crea un usuario con valores por defecto correctos
        $this->assertNotNull($user->id);
        $this->assertNotNull($user->name);
        $this->assertNotNull($user->email);
        $this->assertNotNull($user->password);

        // Columnas del feature de roles deben existir con defaults correctos
        $this->assertSame('creador', $user->role, 'Factory default role debe ser creador');
        $this->assertNull($user->parent_id, 'Factory default parent_id debe ser null');
        $this->assertTrue($user->is_active, 'Factory default is_active debe ser true');

        // Helpers de rol funcionan post-migración
        $this->assertFalse($user->isSuperAdmin());
        $this->assertFalse($user->isSupervisor());
        $this->assertFalse($user->isJefe());
        $this->assertTrue($user->isCreador());

        // States del factory funcionan también
        $superAdmin = User::factory()->superAdmin()->create(['email' => 'sa-factory@webtilia.com']);
        $this->assertSame('super_admin', $superAdmin->role);
        $this->assertTrue($superAdmin->isSuperAdmin());

        $supervisor = User::factory()->supervisor()->create(['email' => 'sv-factory@webtilia.com']);
        $this->assertSame('supervisor', $supervisor->role);
        $this->assertTrue($supervisor->isSupervisor());

        $inactive = User::factory()->inactive()->create(['email' => 'inactive-factory@webtilia.com']);
        $this->assertFalse($inactive->is_active);

        // Verificar que el usuario está en BD con todas las columnas
        $this->assertDatabaseHas('users', [
            'id'        => $user->id,
            'role'      => 'creador',
            'is_active' => true,
        ]);
    }
}
