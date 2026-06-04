<?php

namespace Tests\Feature\WLink;

use App\Models\Page;
use App\Models\PageButton;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageRenderTest extends TestCase
{
    use RefreshDatabase;

    private function makePage(array $overrides = []): Page
    {
        return Page::create(array_merge([
            'slug' => 'demo',
            'title' => 'Demo Page',
            'description' => 'Test',
            'is_active' => true,
        ], $overrides));
    }

    public function test_render_publico_devuelve_200_y_html(): void
    {
        $page = $this->makePage();
        PageButton::create([
            'page_id' => $page->id,
            'label' => '🌐 Visitar web',
            'icon' => '🌐',
            'external_url' => 'https://webtilia.com',
            'order' => 1,
            'is_active' => true,
        ]);

        $resp = $this->get('/p/demo');

        $resp->assertStatus(200);
        $resp->assertSee('Demo Page');
        $resp->assertSee('🌐 Visitar web');
    }

    public function test_render_incrementa_views_count(): void
    {
        $page = $this->makePage(['slug' => 'incr']);

        $this->get('/p/incr');
        $this->get('/p/incr');
        $this->get('/p/incr');

        $this->assertSame(3, $page->fresh()->views_count);
    }

    public function test_page_inactiva_devuelve_404(): void
    {
        $this->makePage(['slug' => 'pausada', 'is_active' => false]);

        $resp = $this->get('/p/pausada');

        $resp->assertStatus(404);
    }

    public function test_page_inexistente_devuelve_404(): void
    {
        $resp = $this->get('/p/noexiste');
        $resp->assertStatus(404);
    }

    public function test_click_boton_externo_redirige_302_y_cuenta_engagement(): void
    {
        $page = $this->makePage(['slug' => 'click-ext']);
        $btn = PageButton::create([
            'page_id' => $page->id,
            'label' => 'Ir',
            'external_url' => 'https://webtilia.com',
            'order' => 1,
            'is_active' => true,
        ]);

        $resp = $this->get("/p/click-ext/c/{$btn->id}");

        $resp->assertStatus(302);
        $resp->assertRedirect('https://webtilia.com');
        $this->assertSame(1, $btn->fresh()->engagements_count);
    }

    public function test_click_boton_inactivo_devuelve_404(): void
    {
        $page = $this->makePage(['slug' => 'inact']);
        $btn = PageButton::create([
            'page_id' => $page->id,
            'label' => 'Pausado',
            'external_url' => 'https://webtilia.com',
            'order' => 1,
            'is_active' => false,
        ]);

        $resp = $this->get("/p/inact/c/{$btn->id}");
        $resp->assertStatus(404);
    }

    public function test_click_boton_de_otra_page_devuelve_404(): void
    {
        $pageA = $this->makePage(['slug' => 'a']);
        $pageB = $this->makePage(['slug' => 'b']);
        $btnB = PageButton::create([
            'page_id' => $pageB->id,
            'label' => 'B',
            'external_url' => 'https://x.com',
            'order' => 1,
            'is_active' => true,
        ]);

        // El botón pertenece a Page B pero se accede via slug A
        $resp = $this->get("/p/a/c/{$btnB->id}");
        $resp->assertStatus(404);
    }

    public function test_meta_og_se_renderiza_cuando_hay_avatar(): void
    {
        $page = $this->makePage(['avatar_url' => 'https://webtilia.com/logo.png']);
        $resp = $this->get('/p/' . $page->slug);

        $resp->assertSee('og:image');
        $resp->assertSee('webtilia.com/logo.png');
    }

    public function test_page_sin_botones_muestra_empty_state(): void
    {
        $this->makePage(['slug' => 'sin-btns']);
        $resp = $this->get('/p/sin-btns');

        $resp->assertStatus(200);
        $resp->assertSee('todavía no tiene botones', false);
    }

    public function test_design_tokens_default_si_no_hay_json(): void
    {
        $page = $this->makePage(['slug' => 'default-design']);
        $tokens = $page->designTokens();

        $this->assertSame('clasico', $tokens['template']);
        $this->assertSame('#0066FF', $tokens['bg_color']);
        $this->assertSame(12, $tokens['button_radius']);
    }

    public function test_total_engagements_suma_de_botones(): void
    {
        $page = $this->makePage(['slug' => 'totals']);
        PageButton::create(['page_id' => $page->id, 'label' => 'A', 'external_url' => 'https://a.com', 'order' => 1, 'is_active' => true, 'engagements_count' => 5]);
        PageButton::create(['page_id' => $page->id, 'label' => 'B', 'external_url' => 'https://b.com', 'order' => 2, 'is_active' => true, 'engagements_count' => 3]);
        PageButton::create(['page_id' => $page->id, 'label' => 'C', 'external_url' => 'https://c.com', 'order' => 3, 'is_active' => true, 'engagements_count' => 7]);

        $this->assertSame(15, $page->totalEngagements());
    }
}
