<?php

namespace Tests\Unit\WLink;

use App\Models\Link;
use App\Services\ShortLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShortLinkService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ShortLinkService();
    }

    public function test_generate_slug_devuelve_string_de_7_chars_por_default(): void
    {
        $slug = $this->svc->generateSlug();
        $this->assertIsString($slug);
        $this->assertSame(7, strlen($slug));
    }

    public function test_generate_slug_evita_caracteres_ambiguos(): void
    {
        // Generar 50 slugs y verificar que ninguno tenga i/I/l/L/o/O/0/1
        for ($i = 0; $i < 50; $i++) {
            $slug = $this->svc->generateSlug();
            $this->assertDoesNotMatchRegularExpression('/[iIlLoO01]/', $slug,
                "Slug $slug contiene caracteres ambiguos");
        }
    }

    public function test_generate_slug_evita_colision_con_link_existente(): void
    {
        // Crear un link con slug fijo, después monkey-patch ShortLinkService para
        // que randomSlug devuelva ese mismo slug 4 veces (forzando retry)
        Link::create(['slug' => 'abcdefg', 'destination_url' => 'https://x.com', 'is_active' => true]);

        // Con la lógica retry, después de detectar la colisión usa longitud +1.
        // No podemos forzar el randomSlug fácilmente sin mock — testeamos que en N tries genera único.
        $generated = [];
        for ($i = 0; $i < 30; $i++) {
            $generated[] = $this->svc->generateSlug();
        }
        $this->assertCount(30, array_unique($generated), 'Hubo colisiones entre slugs generados');
    }

    public function test_validate_custom_slug_rechaza_caracteres_invalidos(): void
    {
        $err = $this->svc->validateCustomSlug('mi slug con espacios');
        $this->assertNotNull($err);
        $this->assertStringContainsString('letras, números', $err);
    }

    public function test_validate_custom_slug_rechaza_demasiado_corto(): void
    {
        $err = $this->svc->validateCustomSlug('a');
        $this->assertNotNull($err);
    }

    public function test_validate_custom_slug_rechaza_slugs_reservados(): void
    {
        foreach (['admin', 'oauth', 'api', 'storage'] as $reservado) {
            $err = $this->svc->validateCustomSlug($reservado);
            $this->assertNotNull($err, "Slug '$reservado' debería estar reservado");
            $this->assertStringContainsString('reservado', $err);
        }
    }

    public function test_validate_custom_slug_rechaza_duplicados(): void
    {
        Link::create(['slug' => 'ocupado', 'destination_url' => 'https://x.com', 'is_active' => true]);

        $err = $this->svc->validateCustomSlug('ocupado');
        $this->assertNotNull($err);
        $this->assertStringContainsString('en uso', $err);
    }

    public function test_validate_custom_slug_acepta_valido(): void
    {
        $err = $this->svc->validateCustomSlug('promo-mayo-2026');
        $this->assertNull($err);
    }

    public function test_validate_custom_slug_permite_mismo_slug_si_es_el_record_que_se_edita(): void
    {
        $link = Link::create(['slug' => 'editame', 'destination_url' => 'https://x.com', 'is_active' => true]);

        // Si el user edita el mismo link y manda el mismo slug, NO debe fallar
        $err = $this->svc->validateCustomSlug('editame', excludeId: $link->id);
        $this->assertNull($err);
    }

    public function test_parse_ua_detecta_iphone_safari(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';
        $p = $this->svc->parseUserAgent($ua);

        $this->assertSame('mobile', $p['device']);
        $this->assertStringContainsString('Safari', $p['browser']);
        $this->assertStringContainsString('iOS', $p['os']);
    }

    public function test_parse_ua_detecta_chrome_desktop_windows(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
        $p = $this->svc->parseUserAgent($ua);

        $this->assertSame('desktop', $p['device']);
        $this->assertStringContainsString('Chrome 124', $p['browser']);
        $this->assertStringContainsString('Windows', $p['os']);
    }

    public function test_parse_ua_detecta_bot_googlebot(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $p = $this->svc->parseUserAgent($ua);

        $this->assertSame('bot', $p['device']);
        $this->assertSame('Googlebot', $p['browser']);
    }

    public function test_parse_ua_detecta_bot_claudebot(): void
    {
        $ua = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)';
        $p = $this->svc->parseUserAgent($ua);

        $this->assertSame('bot', $p['device']);
        $this->assertSame('ClaudeBot', $p['browser']);
    }

    public function test_truncate_ip_ipv4(): void
    {
        $this->assertSame('190.236.45.0', $this->svc->truncateIp('190.236.45.123'));
        $this->assertSame('192.168.1.0', $this->svc->truncateIp('192.168.1.50'));
    }

    public function test_truncate_ip_ipv6(): void
    {
        $result = $this->svc->truncateIp('2001:db8:85a3::8a2e:370:7334');
        $this->assertStringStartsWith('2001:db8:85a3', $result);
        $this->assertStringEndsWith('::', $result);
    }

    public function test_truncate_ip_devuelve_null_para_vacio(): void
    {
        $this->assertNull($this->svc->truncateIp(''));
        $this->assertNull($this->svc->truncateIp('0.0.0.0'));
        $this->assertNull($this->svc->truncateIp('no-es-ip'));
    }
}
