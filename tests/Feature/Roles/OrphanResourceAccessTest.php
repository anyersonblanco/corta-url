<?php

namespace Tests\Feature\Roles;

use App\Filament\Resources\OrphanAccountsResource;
use App\Filament\Resources\OrphanCreadoresResource;
use App\Filament\Resources\OrphanJefesResource;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de acceso a los OrphanResources y correcta segmentacion de datos (Fase 5).
 *
 * Cubre:
 *  - super_admin ve los 3 OrphanResources (canViewAny = true)
 *  - supervisor/jefe/creador no ven ninguno (canViewAny = false)
 *  - OrphanCreadoresResource::getEloquentQuery() lista solo creadores huerfanos
 *  - OrphanJefesResource::getEloquentQuery() lista solo jefes huerfanos
 *  - OrphanAccountsResource::getEloquentQuery() lista solo accounts huerfanas
 *  - canCreate/canEdit/canDelete = false en los 3 resources
 */
class OrphanResourceAccessTest extends TestCase
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
            'name'          => 'Cuenta Orphan ' . uniqid(),
            'supervisor_id' => $supervisor->id,
            'is_active'     => $active,
        ]);
    }

    // =========================================================================
    // canViewAny — super_admin ve los 3 resources
    // =========================================================================

    public function test_super_admin_ve_orphan_creadores_resource(): void
    {
        $sa = $this->makeSuperAdmin('OA1');
        $this->actingAs($sa);

        $this->assertTrue(OrphanCreadoresResource::canViewAny());
    }

    public function test_super_admin_ve_orphan_jefes_resource(): void
    {
        $sa = $this->makeSuperAdmin('OA2');
        $this->actingAs($sa);

        $this->assertTrue(OrphanJefesResource::canViewAny());
    }

    public function test_super_admin_ve_orphan_accounts_resource(): void
    {
        $sa = $this->makeSuperAdmin('OA3');
        $this->actingAs($sa);

        $this->assertTrue(OrphanAccountsResource::canViewAny());
    }

    // =========================================================================
    // canViewAny — otros roles no ven los resources
    // =========================================================================

    public function test_supervisor_no_ve_orphan_resources(): void
    {
        $sv = $this->makeSupervisor('OB1');
        $this->actingAs($sv);

        $this->assertFalse(OrphanCreadoresResource::canViewAny());
        $this->assertFalse(OrphanJefesResource::canViewAny());
        $this->assertFalse(OrphanAccountsResource::canViewAny());
    }

    public function test_jefe_no_ve_orphan_resources(): void
    {
        $sv   = $this->makeSupervisor('OC1');
        $jefe = $this->makeJefe($sv, 'OC1');
        $this->actingAs($jefe);

        $this->assertFalse(OrphanCreadoresResource::canViewAny());
        $this->assertFalse(OrphanJefesResource::canViewAny());
        $this->assertFalse(OrphanAccountsResource::canViewAny());
    }

    public function test_creador_no_ve_orphan_resources(): void
    {
        $sv      = $this->makeSupervisor('OD1');
        $jefe    = $this->makeJefe($sv, 'OD1');
        $creador = $this->makeCreador($jefe, 'OD1');
        $this->actingAs($creador);

        $this->assertFalse(OrphanCreadoresResource::canViewAny());
        $this->assertFalse(OrphanJefesResource::canViewAny());
        $this->assertFalse(OrphanAccountsResource::canViewAny());
    }

    // =========================================================================
    // getEloquentQuery() — scoping correcto por tipo de huerfano
    // =========================================================================

    public function test_orphan_creadores_resource_lista_solo_creadores_huerfanos(): void
    {
        $sa = $this->makeSuperAdmin('OE1');
        $this->actingAs($sa);

        $svActiva    = $this->makeSupervisor('OE1');
        $svInactiva  = $this->makeSupervisor('OE1b', false);
        $jefeOk      = $this->makeJefe($svActiva, 'OE1');
        $jefeInact   = $this->makeJefe($svActiva, 'OE1b', false);

        // Creador con jefe activo (NO huerfano)
        $creadorOk       = $this->makeCreador($jefeOk, 'OE1');
        // Creador con jefe inactivo (SI huerfano)
        $creadorHuerfano = $this->makeCreador($jefeInact, 'OE1b');
        // Jefe huerfano (no debe aparecer en Creadores)
        $jefeHuerfano    = $this->makeJefe($svInactiva, 'OE1c');

        $ids = OrphanCreadoresResource::getEloquentQuery()->pluck('id');

        $this->assertContains($creadorHuerfano->id, $ids);
        $this->assertNotContains($creadorOk->id, $ids);
        $this->assertNotContains($jefeHuerfano->id, $ids); // es jefe, no creador
    }

    public function test_orphan_jefes_resource_lista_solo_jefes_huerfanos(): void
    {
        $sa = $this->makeSuperAdmin('OF1');
        $this->actingAs($sa);

        $svActiva   = $this->makeSupervisor('OF1');
        $svInactiva = $this->makeSupervisor('OF1b', false);
        $jefeOk     = $this->makeJefe($svActiva, 'OF1');
        $jefeHuerfano = $this->makeJefe($svInactiva, 'OF1b');

        // Creador huerfano (no debe aparecer en Jefes)
        $jefeInact   = $this->makeJefe($svActiva, 'OF1c', false);
        $creadorHuerfano = $this->makeCreador($jefeInact, 'OF1');

        $ids = OrphanJefesResource::getEloquentQuery()->pluck('id');

        $this->assertContains($jefeHuerfano->id, $ids);
        $this->assertNotContains($jefeOk->id, $ids);
        $this->assertNotContains($creadorHuerfano->id, $ids); // es creador, no jefe
    }

    public function test_orphan_accounts_resource_lista_solo_accounts_huerfanas(): void
    {
        $sa = $this->makeSuperAdmin('OG1');
        $this->actingAs($sa);

        $svActiva   = $this->makeSupervisor('OG1');
        $svInactiva = $this->makeSupervisor('OG1b', false);

        $cuentaOk      = $this->makeAccount($svActiva);
        $cuentaHuerfana = $this->makeAccount($svInactiva);
        $cuentaInactiva = $this->makeAccount($svInactiva, false);

        $ids = OrphanAccountsResource::getEloquentQuery()->pluck('id');

        $this->assertContains($cuentaHuerfana->id, $ids);
        $this->assertNotContains($cuentaOk->id, $ids);
        $this->assertNotContains($cuentaInactiva->id, $ids);
    }

    // =========================================================================
    // canCreate/canEdit/canDelete = false en los 3 resources
    // =========================================================================

    public function test_orphan_resources_son_read_only(): void
    {
        $sa = $this->makeSuperAdmin('OH1');
        $this->actingAs($sa);

        // canCreate
        $this->assertFalse(OrphanCreadoresResource::canCreate());
        $this->assertFalse(OrphanJefesResource::canCreate());
        $this->assertFalse(OrphanAccountsResource::canCreate());

        // canEdit y canDelete requieren un record; pasamos un dummy model
        $dummyUser    = $this->makeJefe($this->makeSupervisor('OH1b'), 'OH1');
        $dummyAccount = $this->makeAccount($this->makeSupervisor('OH1c'));

        $this->assertFalse(OrphanCreadoresResource::canEdit($dummyUser));
        $this->assertFalse(OrphanJefesResource::canEdit($dummyUser));
        $this->assertFalse(OrphanAccountsResource::canEdit($dummyAccount));

        $this->assertFalse(OrphanCreadoresResource::canDelete($dummyUser));
        $this->assertFalse(OrphanJefesResource::canDelete($dummyUser));
        $this->assertFalse(OrphanAccountsResource::canDelete($dummyAccount));
    }
}
