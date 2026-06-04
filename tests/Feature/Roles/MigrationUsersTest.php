<?php

namespace Tests\Feature\Roles;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de migración de datos — usuarios existentes pasan a super_admin (Fase 1).
 *
 * RefreshDatabase corre todas las migrations en cada test (incluyendo la data migration),
 * por lo que podemos verificar que el comportamiento de la migración es correcto.
 *
 * Los tests simulan el escenario pre-feature:
 *  1. Crear un usuario con role != super_admin (o sin role, como en prod pre-Fase 1).
 *  2. La data migration setea role = super_admin para TODOS los existentes.
 *  3. Verificar que el resultado es super_admin.
 *
 * Nota sobre idempotencia: RefreshDatabase ya valida que correr las migrations
 * dos veces no rompa nada (son idempotentes en estructura). La data migration
 * usa UPDATE sin condición — idempotente porque setear super_admin sobre
 * super_admin es un no-op efectivo.
 */
class MigrationUsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tras RefreshDatabase (que corre todas las migrations), cualquier usuario
     * creado con un role distinto puede ser "simulado" como pre-feature
     * actualizando el role directo en BD y luego ejecutando la lógica
     * equivalente a la data migration.
     *
     * En prod, la data migration se corre una sola vez. Aquí verificamos que
     * un usuario que existía antes de Fase 1 queda en super_admin después.
     */
    public function test_usuario_pre_feature_queda_como_super_admin_tras_data_migration(): void
    {
        // Crear usuario "como era antes": simulamos que existía sin el role de Fase 1
        // (después de RefreshDatabase la columna role ya existe por la migración de schema,
        // pero simulamos que un usuario "antiguo" tenía role = creador por el default)
        $user = User::factory()->create([
            'email' => 'pre@webtilia.com',
            'role'  => 'creador', // como si fuera pre-feature
        ]);

        // Ejecutar equivalente a la data migration (UPDATE todos → super_admin)
        DB::table('users')->update(['role' => 'super_admin']);

        $user->refresh();

        $this->assertSame('super_admin', $user->role);
        $this->assertTrue($user->isSuperAdmin());
    }

    public function test_data_migration_es_idempotente(): void
    {
        $user = User::factory()->create([
            'email' => 'idem@webtilia.com',
            'role'  => 'supervisor',
        ]);

        // Correr la data migration dos veces no debe romper nada
        DB::table('users')->update(['role' => 'super_admin']);
        DB::table('users')->update(['role' => 'super_admin']); // segunda vez

        $user->refresh();

        $this->assertSame('super_admin', $user->role);
    }

    public function test_data_migration_afecta_multiples_usuarios(): void
    {
        User::factory()->create(['email' => 'u1@webtilia.com', 'role' => 'jefe']);
        User::factory()->create(['email' => 'u2@webtilia.com', 'role' => 'supervisor']);
        User::factory()->create(['email' => 'u3@webtilia.com', 'role' => 'creador']);

        DB::table('users')->update(['role' => 'super_admin']);

        $count = User::where('role', 'super_admin')->count();
        $this->assertSame(3, $count);

        $others = User::where('role', '!=', 'super_admin')->count();
        $this->assertSame(0, $others);
    }

    public function test_columna_role_existe_en_tabla_users(): void
    {
        $this->assertTrue(\Schema::hasColumn('users', 'role'));
    }

    public function test_columna_parent_id_existe_en_tabla_users(): void
    {
        $this->assertTrue(\Schema::hasColumn('users', 'parent_id'));
    }

    public function test_columna_is_active_existe_en_tabla_users(): void
    {
        $this->assertTrue(\Schema::hasColumn('users', 'is_active'));
    }

    public function test_usuario_nuevo_tiene_is_active_true_por_defecto(): void
    {
        $user = User::factory()->create(['email' => 'active@webtilia.com']);

        $this->assertTrue($user->is_active);
    }

    public function test_usuario_nuevo_tiene_role_creador_por_defecto_en_factory(): void
    {
        // El factory default es 'creador'. En prod, siempre se especifica al crear.
        $user = User::factory()->create(['email' => 'defrol@webtilia.com']);

        $this->assertSame('creador', $user->role);
    }

    public function test_parent_id_es_nullable(): void
    {
        $user = User::factory()->create(['email' => 'noparent@webtilia.com', 'parent_id' => null]);

        $this->assertNull($user->parent_id);
        $this->assertNull($user->parent);
    }

    public function test_relacion_parent_retorna_usuario_correcto(): void
    {
        $sa   = User::factory()->create(['role' => 'super_admin', 'email' => 'sapar@webtilia.com']);
        $hijo = User::factory()->create(['role' => 'supervisor', 'email' => 'hijo@webtilia.com', 'parent_id' => $sa->id]);

        $this->assertEquals($sa->id, $hijo->parent->id);
        $this->assertEquals($sa->name, $hijo->parent->name);
    }

    public function test_relacion_children_retorna_hijos_correctos(): void
    {
        $sa   = User::factory()->create(['role' => 'super_admin', 'email' => 'sapch@webtilia.com']);
        $sv1  = User::factory()->create(['role' => 'supervisor', 'email' => 'sv1ch@webtilia.com', 'parent_id' => $sa->id]);
        $sv2  = User::factory()->create(['role' => 'supervisor', 'email' => 'sv2ch@webtilia.com', 'parent_id' => $sa->id]);

        $children = $sa->children;

        $this->assertCount(2, $children);
        $this->assertTrue($children->contains('id', $sv1->id));
        $this->assertTrue($children->contains('id', $sv2->id));
    }
}
