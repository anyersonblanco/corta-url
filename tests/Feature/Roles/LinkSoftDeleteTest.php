<?php

namespace Tests\Feature\Roles;

use App\Models\Link;
use App\Models\LinkDeletionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de SoftDeletes en el modelo Link (Fase 4).
 *
 * Verifica que:
 *  - Un link archivado (deleted_at != null) devuelve 404 en el redirect público.
 *  - Un link archivado no aparece en queries normales.
 *  - El histórico de clicks (link_clicks) permanece intacto post-archivado.
 *  - Un super_admin puede restaurar el link con withTrashed() + restore().
 */
class LinkSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeLink(array $attrs = []): Link
    {
        return Link::create(array_merge([
            'slug'            => 'test-soft-' . uniqid(),
            'destination_url' => 'https://webtilia.com',
            'is_active'       => true,
        ], $attrs));
    }

    // =========================================================================
    // Redirect público
    // =========================================================================

    public function test_link_archivado_devuelve_404_en_redirect(): void
    {
        $link = $this->makeLink(['slug' => 'archived-slug']);
        $link->delete(); // SoftDelete

        $resp = $this->get('/l/archived-slug');
        $resp->assertStatus(404);
    }

    public function test_link_activo_sigue_redirigiendo_despues_de_archivar_otro(): void
    {
        // Asegura que solo el archivado desaparece, los demás siguen activos.
        $this->makeLink(['slug' => 'archived-one'])->delete();
        $this->makeLink(['slug' => 'still-active']);

        $this->get('/l/archived-one')->assertStatus(404);
        $this->get('/l/still-active')->assertStatus(302);
    }

    // =========================================================================
    // Invisibilidad en queries normales
    // =========================================================================

    public function test_link_archivado_no_aparece_en_query_normal(): void
    {
        $link = $this->makeLink(['slug' => 'hidden-link']);
        $link->delete();

        $found = Link::where('slug', 'hidden-link')->first();
        $this->assertNull($found);
    }

    public function test_link_archivado_si_aparece_con_withTrashed(): void
    {
        $link = $this->makeLink(['slug' => 'with-trashed']);
        $link->delete();

        $found = Link::withTrashed()->where('slug', 'with-trashed')->first();
        $this->assertNotNull($found);
        $this->assertNotNull($found->deleted_at);
    }

    // =========================================================================
    // Clicks históricos
    // =========================================================================

    public function test_clicks_historicos_permanecen_intactos_post_archivado(): void
    {
        $link = $this->makeLink(['slug' => 'historic-clicks']);

        // Registra 3 clicks vía HTTP para crear link_clicks reales
        $this->get('/l/historic-clicks');
        $this->get('/l/historic-clicks');
        $this->get('/l/historic-clicks');

        $countBefore = $link->clicks()->count();
        $this->assertSame(3, $countBefore);

        // Archivar el link
        $link->delete();

        // Los clicks siguen en BD (la relación usa withTrashed implícitamente
        // porque HasMany no requiere que el padre exista para consultar hijos).
        $countAfter = \App\Models\LinkClick::where('link_id', $link->id)->count();
        $this->assertSame(3, $countAfter, 'Los clicks deben sobrevivir al soft-delete del link');
    }

    // =========================================================================
    // Restore
    // =========================================================================

    public function test_super_admin_puede_restaurar_link_archivado(): void
    {
        $link = $this->makeLink(['slug' => 'restore-me']);
        $link->delete();

        $this->assertNull(Link::where('slug', 'restore-me')->first());

        // Restaurar
        Link::withTrashed()->where('slug', 'restore-me')->first()->restore();

        $restored = Link::where('slug', 'restore-me')->first();
        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_at);

        // Vuelve a redirigir
        $this->get('/l/restore-me')->assertStatus(302);
    }
}
