<?php

namespace Tests\Feature\Roles;

use App\Models\Link;
use App\Models\LinkClick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Tests de scope del LinkStatsWidget (Fase 3).
 *
 * Verifica que las queries del widget cuenten solo los datos de la rama
 * del usuario autenticado.
 *
 * Estrategia: reproducir la lógica de las queries del widget directamente
 * (sin instanciar el widget de Filament que requiere contexto Livewire).
 * El scope es idéntico — whereHas con branchUserIds().
 */
class LinkStatsWidgetScopeTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeSuperAdmin(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'super_admin',
            'email' => "sa{$suffix}_sw@webtilia.com",
        ]);
    }

    private function makeSupervisor(string $suffix = ''): User
    {
        return User::factory()->create([
            'role'  => 'supervisor',
            'email' => "sv{$suffix}_sw@webtilia.com",
        ]);
    }

    private function makeJefe(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'jefe',
            'email'     => "j{$suffix}_sw@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makeCreador(User $parent, string $suffix = ''): User
    {
        return User::factory()->create([
            'role'      => 'creador',
            'email'     => "c{$suffix}_sw@webtilia.com",
            'parent_id' => $parent->id,
        ]);
    }

    private function makeLink(User $owner, int $clicksCount = 0): Link
    {
        return Link::factory()->create([
            'created_by'   => $owner->id,
            'clicks_count' => $clicksCount,
            'is_active'    => true,
        ]);
    }

    private function makeClick(Link $link): LinkClick
    {
        $click = new LinkClick();
        $click->link_id = $link->id;
        $click->save();
        // Forzar created_at manualmente (timestamps=false en el modelo)
        \DB::table('link_clicks')->where('id', $click->id)->update([
            'created_at' => now()->toDateTimeString(),
        ]);
        return $click->refresh();
    }

    /**
     * Replica la query de totalClicks del widget para un user dado.
     */
    private function totalClicksForUser(User $user): int
    {
        $branchIds = $user->branchUserIds()->all();
        return (int) Link::whereIn('created_by', $branchIds)->sum('clicks_count');
    }

    /**
     * Replica la query de enlacesActivos del widget para un user dado.
     */
    private function enlacesActivosForUser(User $user): int
    {
        $branchIds = $user->branchUserIds()->all();
        return Link::active()->whereIn('created_by', $branchIds)->count();
    }

    /**
     * Replica la query de clicksLast24h del widget para un user dado.
     */
    private function clicksLast24hForUser(User $user): int
    {
        $branchIds = $user->branchUserIds()->all();
        return LinkClick::whereHas('link', function ($q) use ($branchIds) {
            $q->whereIn('created_by', $branchIds);
        })->where('created_at', '>=', now()->subDay())->count();
    }

    // =========================================================================
    // super_admin ve stats globales
    // =========================================================================

    public function test_super_admin_totalClicks_incluye_todos_los_links(): void
    {
        $sa = $this->makeSuperAdmin('1');
        $sv = $this->makeSupervisor('1');
        $j  = $this->makeJefe($sv, '1');
        $c  = $this->makeCreador($j, '1');

        $this->makeLink($sa, 5);
        $this->makeLink($c, 10);
        $this->makeLink($sv, 3);

        $total = $this->totalClicksForUser($sa);

        $this->assertEquals(18, $total, 'super_admin debe sumar todos los clicks del sistema');
    }

    // =========================================================================
    // supervisor solo cuenta su rama
    // =========================================================================

    public function test_supervisor_totalClicks_solo_cuenta_su_rama(): void
    {
        $sv1 = $this->makeSupervisor('2a');
        $sv2 = $this->makeSupervisor('2b');

        $j1  = $this->makeJefe($sv1, '2a');
        $c1  = $this->makeCreador($j1, '2a');

        $j2  = $this->makeJefe($sv2, '2b');
        $c2  = $this->makeCreador($j2, '2b');

        $this->makeLink($sv1, 5);
        $this->makeLink($c1, 3);
        $this->makeLink($j1, 2);

        $this->makeLink($sv2, 100); // NO debe entrar en los stats de sv1
        $this->makeLink($c2, 50);   // NO debe entrar en los stats de sv1

        $total = $this->totalClicksForUser($sv1);

        $this->assertEquals(10, $total, 'supervisor debe contar solo clicks de su rama');
    }

    // =========================================================================
    // jefe solo cuenta su rama
    // =========================================================================

    public function test_jefe_enlacesActivos_solo_cuenta_su_rama(): void
    {
        $sv = $this->makeSupervisor('3');
        $j1 = $this->makeJefe($sv, '3a');
        $j2 = $this->makeJefe($sv, '3b');
        $c1 = $this->makeCreador($j1, '3a');
        $c2 = $this->makeCreador($j2, '3b');

        $this->makeLink($j1);   // activo de j1
        $this->makeLink($c1);   // activo de c1 (bajo j1)
        $this->makeLink($j2);   // activo de j2 — NO debe contarse para j1
        $this->makeLink($c2);   // activo de c2 — NO debe contarse para j1

        $activos = $this->enlacesActivosForUser($j1);

        $this->assertEquals(2, $activos, 'jefe debe contar solo enlaces activos de su rama');
    }

    // =========================================================================
    // creador solo cuenta sus propios stats
    // =========================================================================

    public function test_creador_totalClicks_solo_sus_propios_links(): void
    {
        $sv = $this->makeSupervisor('4');
        $j  = $this->makeJefe($sv, '4');
        $c1 = $this->makeCreador($j, '4a');
        $c2 = $this->makeCreador($j, '4b');

        $this->makeLink($c1, 7);
        $this->makeLink($c2, 99); // NO debe contarse para c1

        $total = $this->totalClicksForUser($c1);

        $this->assertEquals(7, $total, 'creador debe contar solo sus propios clicks');
    }

    // =========================================================================
    // clicksLast24h filtrado por rama
    // =========================================================================

    public function test_clicksLast24h_filtrado_por_rama(): void
    {
        $sv1 = $this->makeSupervisor('5a');
        $sv2 = $this->makeSupervisor('5b');
        $c1  = $this->makeCreador($this->makeJefe($sv1, '5a'), '5a');
        $c2  = $this->makeCreador($this->makeJefe($sv2, '5b'), '5b');

        $linkC1 = $this->makeLink($c1);
        $linkC2 = $this->makeLink($c2);

        // 2 clicks al link de la rama de sv1
        $this->makeClick($linkC1);
        $this->makeClick($linkC1);
        // 5 clicks al link de la rama de sv2 — NO deben entrar en sv1
        for ($i = 0; $i < 5; $i++) {
            $this->makeClick($linkC2);
        }

        $clicks = $this->clicksLast24hForUser($sv1);

        $this->assertEquals(2, $clicks, 'clicks 24h deben filtrarse por rama del user');
    }
}
