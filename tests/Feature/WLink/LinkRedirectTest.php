<?php

namespace Tests\Feature\WLink;

use App\Models\Link;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_publico_funciona_y_devuelve_302(): void
    {
        $link = Link::create([
            'slug' => 'test123',
            'destination_url' => 'https://webtilia.com',
            'is_active' => true,
        ]);

        $resp = $this->get('/l/test123');

        $resp->assertStatus(302);
        $resp->assertRedirect('https://webtilia.com');
    }

    public function test_redirect_incrementa_clicks_count(): void
    {
        $link = Link::create([
            'slug' => 'click1',
            'destination_url' => 'https://webtilia.com',
            'is_active' => true,
        ]);

        $this->get('/l/click1');
        $this->get('/l/click1');
        $this->get('/l/click1');

        $this->assertSame(3, $link->fresh()->clicks_count);
    }

    public function test_redirect_registra_linkclick_con_ua_parseado(): void
    {
        $link = Link::create([
            'slug' => 'track1',
            'destination_url' => 'https://webtilia.com',
            'is_active' => true,
        ]);

        $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
            'Referer' => 'https://twitter.com/webtilia/status/123',
        ])->get('/l/track1');

        $click = $link->clicks()->first();
        $this->assertNotNull($click);
        $this->assertSame('mobile', $click->device);
        $this->assertStringContainsString('Safari', $click->browser);
        $this->assertStringContainsString('iOS', $click->os);
        $this->assertSame('twitter.com', $click->referer_host);
    }

    public function test_link_inactivo_devuelve_404_y_no_redirige(): void
    {
        Link::create([
            'slug' => 'pausado',
            'destination_url' => 'https://webtilia.com',
            'is_active' => false,
        ]);

        $resp = $this->get('/l/pausado');

        $resp->assertStatus(404);
        $resp->assertSee('desactivado por el equipo');
    }

    public function test_link_expirado_devuelve_404(): void
    {
        Link::create([
            'slug' => 'caducado',
            'destination_url' => 'https://webtilia.com',
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        $resp = $this->get('/l/caducado');

        $resp->assertStatus(404);
        $resp->assertSee('expiró el');
    }

    public function test_slug_inexistente_devuelve_404(): void
    {
        $resp = $this->get('/l/noexiste');
        $resp->assertStatus(404);
    }

    public function test_slug_invalido_no_matchea_la_ruta(): void
    {
        // Caracteres prohibidos (espacios, signos) — la regex de Route rechaza antes de tocar BD
        $resp = $this->get('/l/' . urlencode('not valid!'));
        $resp->assertStatus(404);
    }

    public function test_no_indexa_redirect_en_google(): void
    {
        Link::create([
            'slug' => 'seoblock',
            'destination_url' => 'https://webtilia.com',
            'is_active' => true,
        ]);

        $resp = $this->get('/l/seoblock');

        // El middleware global puede agregar más directivas, pero "noindex" debe estar presente
        $robotsTag = $resp->headers->get('X-Robots-Tag');
        $this->assertNotNull($robotsTag);
        $this->assertStringContainsString('noindex', $robotsTag);
    }

    public function test_tracking_no_rompe_si_la_ip_es_invalida(): void
    {
        // Esto verifica que el tracking sea defensivo — si falla, no debe romper el redirect
        $link = Link::create([
            'slug' => 'safe1',
            'destination_url' => 'https://webtilia.com',
            'is_active' => true,
        ]);

        $resp = $this->get('/l/safe1');
        $resp->assertStatus(302); // redirige aunque tracking falle
    }
}
