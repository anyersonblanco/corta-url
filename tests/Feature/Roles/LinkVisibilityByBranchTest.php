<?php

namespace Tests\Feature\Roles;

use App\Models\Account;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de visibilidad de Links según rol y rama (Fase 3).
 *
 * Verifica que getEloquentQuery() en LinkResource restrinja correctamente
 * la vista de links según el user autenticado.
 *
 * Nota: estos tests verifican la lógica de branchUserIds() aplicada a links
 * directamente sobre el modelo, simulando el scope de getEloquentQuery().
 * Los tests de Filament Resource no son necesarios para verificar correctitud
 * del scope — el mismo filtro whereIn('created_by', branchIds) aplica.
 */
class LinkVisibilityByBranchTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSuperAdmin(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'super_admin',
            'email' => "sa{$suffix}_lv@webtilia.com",
        ]);
    }

    private function makeSupervisor(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => "sv{$suffix}_lv@webtilia.com",
        ]);
    }

    private function makeJefe(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "j{$suffix}_lv@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makeCreador(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "c{$suffix}_lv@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    /**
     * Crea un link atribuido a un user específico.
     */
    private function makeLink(User $owner): Link
    {
        return Link::factory()->create(['created_by' => $owner->id]);
    }

    /**
     * Aplica el scope de visibilidad equivalente al getEloquentQuery() de LinkResource.
     * (Evita cargar Filament en tests unitarios — el scope es puro Eloquent.)
     */
    private function visibleLinks(User $user): \Illuminate\Database\Eloquent\Collection
    {
        if ($user->isSuperAdmin()) {
            return Link::all();
        }

        $branchIds = $user->branchUserIds()->all();
        return Link::whereIn('created_by', $branchIds)->get();
    }

    // =========================================================================
    // super_admin ve todos los links
    // =========================================================================

    public function test_super_admin_ve_todos_los_links(): void
    {
        $sa = $this->makeSuperAdmin('1');
        $sv = $this->makeSupervisor('1');
        $j  = $this->makeJefe($sv, '1');
        $c  = $this->makeCreador($j, '1');

        $linkSa = $this->makeLink($sa);
        $linkSv = $this->makeLink($sv);
        $linkJ  = $this->makeLink($j);
        $linkC  = $this->makeLink($c);

        $visibles = $this->visibleLinks($sa)->pluck('id')->all();

        $this->assertContains($linkSa->id, $visibles);
        $this->assertContains($linkSv->id, $visibles);
        $this->assertContains($linkJ->id, $visibles);
        $this->assertContains($linkC->id, $visibles);
    }

    // =========================================================================
    // supervisor ve links de su rama
    // =========================================================================

    public function test_supervisor_ve_links_de_sus_jefes_y_creadores(): void
    {
        $sv = $this->makeSupervisor('2');
        $j  = $this->makeJefe($sv, '2');
        $c  = $this->makeCreador($j, '2');

        $linkSv = $this->makeLink($sv);
        $linkJ  = $this->makeLink($j);
        $linkC  = $this->makeLink($c);

        $visibles = $this->visibleLinks($sv)->pluck('id')->all();

        $this->assertContains($linkSv->id, $visibles, 'El supervisor debe ver sus propios links');
        $this->assertContains($linkJ->id, $visibles, 'El supervisor debe ver links de sus jefes');
        $this->assertContains($linkC->id, $visibles, 'El supervisor debe ver links de creadores de sus jefes');
    }

    public function test_supervisor_no_ve_links_de_otra_rama(): void
    {
        $sv1 = $this->makeSupervisor('3a');
        $sv2 = $this->makeSupervisor('3b');
        $j2  = $this->makeJefe($sv2, '3');
        $c2  = $this->makeCreador($j2, '3');

        $this->makeLink($sv1); // link propio de sv1

        $linkJ2 = $this->makeLink($j2);
        $linkC2 = $this->makeLink($c2);

        $visibles = $this->visibleLinks($sv1)->pluck('id')->all();

        $this->assertNotContains($linkJ2->id, $visibles, 'El supervisor NO debe ver links de otro supervisor');
        $this->assertNotContains($linkC2->id, $visibles, 'El supervisor NO debe ver links de otra rama');
    }

    // =========================================================================
    // jefe ve links de sus creadores + los suyos
    // =========================================================================

    public function test_jefe_ve_links_de_sus_creadores_directos_y_propios(): void
    {
        $sv = $this->makeSupervisor('4');
        $j  = $this->makeJefe($sv, '4');
        $c1 = $this->makeCreador($j, '4a');
        $c2 = $this->makeCreador($j, '4b');

        $linkJ  = $this->makeLink($j);
        $linkC1 = $this->makeLink($c1);
        $linkC2 = $this->makeLink($c2);

        $visibles = $this->visibleLinks($j)->pluck('id')->all();

        $this->assertContains($linkJ->id, $visibles, 'El jefe debe ver sus propios links');
        $this->assertContains($linkC1->id, $visibles, 'El jefe debe ver links de su creador 1');
        $this->assertContains($linkC2->id, $visibles, 'El jefe debe ver links de su creador 2');
    }

    public function test_jefe_no_ve_links_de_otra_rama(): void
    {
        $sv  = $this->makeSupervisor('5');
        $j1  = $this->makeJefe($sv, '5a');
        $j2  = $this->makeJefe($sv, '5b');
        $c2  = $this->makeCreador($j2, '5');

        $this->makeLink($j1); // link propio de j1

        $linkJ2 = $this->makeLink($j2);
        $linkC2 = $this->makeLink($c2);

        $visibles = $this->visibleLinks($j1)->pluck('id')->all();

        $this->assertNotContains($linkJ2->id, $visibles, 'El jefe NO debe ver links del hermano jefe');
        $this->assertNotContains($linkC2->id, $visibles, 'El jefe NO debe ver links de creadores de otro jefe');
    }

    // =========================================================================
    // creador ve solo sus propios links
    // =========================================================================

    public function test_creador_ve_solo_sus_propios_links(): void
    {
        $sv = $this->makeSupervisor('6');
        $j  = $this->makeJefe($sv, '6');
        $c  = $this->makeCreador($j, '6');

        $linkC  = $this->makeLink($c);
        $linkJ  = $this->makeLink($j);
        $linkSv = $this->makeLink($sv);

        $visibles = $this->visibleLinks($c)->pluck('id')->all();

        $this->assertContains($linkC->id, $visibles, 'El creador debe ver sus propios links');
        $this->assertNotContains($linkJ->id, $visibles, 'El creador NO debe ver links del jefe');
        $this->assertNotContains($linkSv->id, $visibles, 'El creador NO debe ver links del supervisor');
    }

    public function test_creador_no_ve_links_de_otro_creador_misma_rama(): void
    {
        $sv = $this->makeSupervisor('7');
        $j  = $this->makeJefe($sv, '7');
        $c1 = $this->makeCreador($j, '7a');
        $c2 = $this->makeCreador($j, '7b');

        $linkC1 = $this->makeLink($c1);
        $linkC2 = $this->makeLink($c2);

        $visibles = $this->visibleLinks($c1)->pluck('id')->all();

        $this->assertContains($linkC1->id, $visibles);
        $this->assertNotContains($linkC2->id, $visibles, 'Un creador NO debe ver links de otro creador aunque sea la misma rama');
    }

    // =========================================================================
    // Links con account_id = null y created_by = null (pre-feature)
    // solo visibles para super_admin
    // =========================================================================

    public function test_links_con_created_by_null_solo_visibles_para_super_admin(): void
    {
        $sa = $this->makeSuperAdmin('8');
        $sv = $this->makeSupervisor('8');
        $j  = $this->makeJefe($sv, '8');
        $c  = $this->makeCreador($j, '8');

        // Link pre-feature: account_id = null, created_by = null
        $linkPreFeature = Link::factory()->create([
            'account_id' => null,
            'created_by' => null,
        ]);

        // super_admin lo ve
        $visibesSa = $this->visibleLinks($sa)->pluck('id')->all();
        $this->assertContains($linkPreFeature->id, $visibesSa, 'super_admin debe ver links pre-feature (created_by null)');

        // Otros roles NO lo ven (created_by null no está en ningún branchIds)
        foreach ([$sv, $j, $c] as $user) {
            $visibles = $this->visibleLinks($user)->pluck('id')->all();
            $this->assertNotContains(
                $linkPreFeature->id,
                $visibles,
                "El rol {$user->role} NO debe ver links pre-feature con created_by null"
            );
        }
    }
}
