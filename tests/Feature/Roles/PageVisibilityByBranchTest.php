<?php

namespace Tests\Feature\Roles;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de visibilidad de Pages según rol y rama (Fase 3).
 *
 * Page tiene created_by (FK nullable a users, confirmado en migration).
 * El scope es idéntico al de LinkResource.
 */
class PageVisibilityByBranchTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSuperAdmin(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'super_admin',
            'email' => "sa{$suffix}_pv@webtilia.com",
        ]);
    }

    private function makeSupervisor(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => "sv{$suffix}_pv@webtilia.com",
        ]);
    }

    private function makeJefe(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "j{$suffix}_pv@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makeCreador(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "c{$suffix}_pv@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makePage(User $owner): Page
    {
        return Page::create([
            'slug'       => 'pg-' . uniqid(),
            'title'      => 'Página de ' . $owner->name,
            'is_active'  => true,
            'created_by' => $owner->id,
        ]);
    }

    /**
     * Aplica el scope equivalente al getEloquentQuery() de PageResource.
     */
    private function visiblePages(User $user): \Illuminate\Database\Eloquent\Collection
    {
        if ($user->isSuperAdmin()) {
            return Page::all();
        }

        $branchIds = $user->branchUserIds()->all();
        return Page::whereIn('created_by', $branchIds)->get();
    }

    // =========================================================================
    // super_admin ve todas las páginas
    // =========================================================================

    public function test_super_admin_ve_todas_las_pages(): void
    {
        $sa = $this->makeSuperAdmin('1');
        $sv = $this->makeSupervisor('1');
        $j  = $this->makeJefe($sv, '1');
        $c  = $this->makeCreador($j, '1');

        $pageSa = $this->makePage($sa);
        $pageSv = $this->makePage($sv);
        $pageJ  = $this->makePage($j);
        $pageC  = $this->makePage($c);

        $visibles = $this->visiblePages($sa)->pluck('id')->all();

        $this->assertContains($pageSa->id, $visibles);
        $this->assertContains($pageSv->id, $visibles);
        $this->assertContains($pageJ->id, $visibles);
        $this->assertContains($pageC->id, $visibles);
    }

    // =========================================================================
    // supervisor ve pages de su rama (jefes + creadores)
    // =========================================================================

    public function test_supervisor_ve_pages_de_su_rama(): void
    {
        $sv = $this->makeSupervisor('2');
        $j  = $this->makeJefe($sv, '2');
        $c  = $this->makeCreador($j, '2');

        $pageSv = $this->makePage($sv);
        $pageJ  = $this->makePage($j);
        $pageC  = $this->makePage($c);

        $visibles = $this->visiblePages($sv)->pluck('id')->all();

        $this->assertContains($pageSv->id, $visibles);
        $this->assertContains($pageJ->id, $visibles);
        $this->assertContains($pageC->id, $visibles);
    }

    public function test_supervisor_no_ve_pages_de_otra_rama(): void
    {
        $sv1 = $this->makeSupervisor('3a');
        $sv2 = $this->makeSupervisor('3b');
        $j2  = $this->makeJefe($sv2, '3');
        $c2  = $this->makeCreador($j2, '3');

        $pageJ2 = $this->makePage($j2);
        $pageC2 = $this->makePage($c2);

        $visibles = $this->visiblePages($sv1)->pluck('id')->all();

        $this->assertNotContains($pageJ2->id, $visibles);
        $this->assertNotContains($pageC2->id, $visibles);
    }

    // =========================================================================
    // creador ve solo sus propias pages
    // =========================================================================

    public function test_creador_ve_solo_sus_propias_pages(): void
    {
        $sv = $this->makeSupervisor('4');
        $j  = $this->makeJefe($sv, '4');
        $c1 = $this->makeCreador($j, '4a');
        $c2 = $this->makeCreador($j, '4b');

        $pageC1 = $this->makePage($c1);
        $pageC2 = $this->makePage($c2);
        $pageJ  = $this->makePage($j);

        $visibles = $this->visiblePages($c1)->pluck('id')->all();

        $this->assertContains($pageC1->id, $visibles);
        $this->assertNotContains($pageC2->id, $visibles, 'El creador NO debe ver pages de otro creador');
        $this->assertNotContains($pageJ->id, $visibles, 'El creador NO debe ver pages del jefe');
    }

    // =========================================================================
    // Pages con created_by = null (pre-feature) solo visibles para super_admin
    // =========================================================================

    public function test_pages_con_created_by_null_solo_visibles_para_super_admin(): void
    {
        $sa = $this->makeSuperAdmin('5');
        $sv = $this->makeSupervisor('5');
        $j  = $this->makeJefe($sv, '5');
        $c  = $this->makeCreador($j, '5');

        $pagePreFeature = Page::create([
            'slug'       => 'pre-feat-' . uniqid(),
            'title'      => 'Página pre-feature',
            'is_active'  => true,
            'created_by' => null,
        ]);

        // super_admin la ve
        $visiblesSa = $this->visiblePages($sa)->pluck('id')->all();
        $this->assertContains($pagePreFeature->id, $visiblesSa);

        // Los demás roles NO la ven
        foreach ([$sv, $j, $c] as $user) {
            $visibles = $this->visiblePages($user)->pluck('id')->all();
            $this->assertNotContains(
                $pagePreFeature->id,
                $visibles,
                "El rol {$user->role} NO debe ver pages pre-feature con created_by null"
            );
        }
    }
}
