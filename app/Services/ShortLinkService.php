<?php

namespace App\Services;

use App\Models\Link;
use App\Models\LinkClick;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servicio del módulo WLink.
 *
 * Responsabilidades:
 *  1. generateSlug() — slug aleatorio único, evita colisión (retry)
 *  2. validateSlug() — slug solicitado por el user debe ser válido
 *  3. recordClick() — registra analytics + incrementa counter
 *  4. parseUserAgent() — extrae device/browser/os sin lib externa
 *  5. geolocateIp() — consulta ip-api.com (free, sin key) con cache 24h
 *
 * Privacy: trunca IP a /24 (IPv4) o /48 (IPv6). NO guarda la IP completa.
 */
class ShortLinkService
{
    // Caracteres permitidos en slug auto-generado: a-z A-Z 0-9 (sin chars ambiguos i/I/l/L/o/O/0/1)
    private const SLUG_ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const SLUG_AUTO_LEN = 7; // ~57^7 = 1.95e12 combinaciones, colisión despreciable a <1M links

    // Slugs reservados (no se pueden registrar) — colisionarían con rutas del sistema
    public const RESERVED_SLUGS = [
        'admin', 'oauth', 'api', 'login', 'logout', 'register',
        'storage', 'horizon', 'telescope', 'l', 'link', 'links',
        'p', 'page', 'pages', 'go', 'short', 'redirect',
        'favicon.ico', 'robots.txt', '_remote_exec',
        'scan-reports', 'project-accesses', 'scanner',
    ];

    /**
     * Genera un slug aleatorio único. Si colisiona, retry con +1 carácter.
     * Garantiza unicidad consultando la BD.
     */
    public function generateSlug(int $len = self::SLUG_AUTO_LEN, int $maxAttempts = 5): string
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $slug = $this->randomSlug($len + $attempt);
            if (!Link::where('slug', $slug)->exists()) {
                return $slug;
            }
        }
        // Improbable. Fallback: incluir timestamp.
        return $this->randomSlug($len) . substr((string) time(), -4);
    }

    private function randomSlug(int $len): string
    {
        $alphabet = self::SLUG_ALPHABET;
        $max = strlen($alphabet) - 1;
        $slug = '';
        for ($i = 0; $i < $len; $i++) {
            $slug .= $alphabet[random_int(0, $max)];
        }
        return $slug;
    }

    /**
     * Valida un slug ingresado por el usuario. Retorna null si OK, mensaje de error si no.
     */
    public function validateCustomSlug(string $slug, ?int $excludeId = null): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $slug)) {
            return 'El slug debe tener entre 2 y 64 caracteres y solo puede contener letras, números, guiones y guiones bajos.';
        }
        if (in_array(strtolower($slug), self::RESERVED_SLUGS, true)) {
            return 'Ese slug está reservado por el sistema (colisiona con una ruta). Probá otro.';
        }
        $existsQuery = Link::where('slug', $slug);
        if ($excludeId !== null) {
            $existsQuery->where('id', '!=', $excludeId);
        }
        if ($existsQuery->exists()) {
            return 'Ese slug ya está en uso por otro enlace.';
        }
        return null;
    }

    /**
     * Valida slug para Page (mismas reglas que Link pero verifica unicidad en `pages`).
     */
    public function validatePageSlug(string $slug, ?int $excludeId = null): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{2,64}$/', $slug)) {
            return 'El slug debe tener entre 2 y 64 caracteres y solo puede contener letras, números, guiones y guiones bajos.';
        }
        if (in_array(strtolower($slug), self::RESERVED_SLUGS, true)) {
            return 'Ese slug está reservado por el sistema. Probá otro.';
        }
        $existsQuery = \App\Models\Page::where('slug', $slug);
        if ($excludeId !== null) {
            $existsQuery->where('id', '!=', $excludeId);
        }
        if ($existsQuery->exists()) {
            return 'Ese slug ya está en uso por otra Página.';
        }
        return null;
    }

    /** Genera slug aleatorio único en la tabla `pages`. */
    public function generatePageSlug(int $len = self::SLUG_AUTO_LEN, int $maxAttempts = 5): string
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $slug = $this->randomSlug($len + $attempt);
            if (!\App\Models\Page::where('slug', $slug)->exists()) {
                return $slug;
            }
        }
        return $this->randomSlug($len) . substr((string) time(), -4);
    }

    /**
     * Registra un click sobre un link. Llamar desde el redirect controller.
     *
     * Cada hit a /l/{slug} suma +1 al contador y guarda 1 fila en link_clicks.
     * Sin filtros — el counter refleja exactamente cuántas veces se abrió la URL
     * corta. Si querés saber cuántos fueron humanos vs bots, los analytics
     * muestran el desglose por device (incluye categoría "bot").
     */
    public function recordClick(Link $link, Request $request): void
    {
        try {
            $ua = (string) $request->header('User-Agent', '');
            $parsed = $this->parseUserAgent($ua);

            $rawIp = $request->ip() ?? '';
            $truncatedIp = $this->truncateIp($rawIp);
            $geo = $rawIp ? $this->geolocateIp($rawIp) : null;

            $refererHost = null;
            $referer = $request->header('Referer');
            if ($referer) {
                $refererHost = parse_url($referer, PHP_URL_HOST) ?: null;
            }

            LinkClick::create([
                'link_id' => $link->id,
                'ip_truncated' => $truncatedIp,
                'country' => $geo['country'] ?? null,
                'country_name' => $geo['country_name'] ?? null,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'os' => $parsed['os'],
                'referer_host' => $refererHost,
                'user_agent' => Str::limit($ua, 500, ''),
            ]);

            $link->incrementCounter();
        } catch (\Throwable $e) {
            // El tracking NUNCA debe romper el redirect. Si falla, log y seguir.
            Log::warning('WLink recordClick fallo: ' . $e->getMessage());
        }
    }

    /**
     * Parse simple del User-Agent (sin dependencia externa).
     * No es perfecto pero cubre ~95% de los UAs en producción.
     *
     * @return array{device:string, browser:string, os:string}
     */
    public function parseUserAgent(string $ua): array
    {
        $ua = trim($ua);
        if ($ua === '') {
            return ['device' => 'unknown', 'browser' => 'unknown', 'os' => 'unknown'];
        }

        // Bots primero (no son device humano)
        if (preg_match('/bot|crawler|spider|googlebot|bingbot|claudebot|gptbot|perplexitybot/i', $ua)) {
            return ['device' => 'bot', 'browser' => $this->extractBotName($ua), 'os' => 'bot'];
        }

        // Device
        $device = 'desktop';
        if (preg_match('/tablet|ipad/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|android.+mobile|iphone|ipod|blackberry|windows phone/i', $ua)) {
            $device = 'mobile';
        } elseif (preg_match('/android/i', $ua)) {
            // android sin "mobile" = tablet típicamente
            $device = 'tablet';
        }

        // Browser
        $browser = 'Otro';
        if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Edge ' . $this->majorVersion($m[1]);
        } elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m) && !preg_match('/Edg|OPR|YaBrowser/i', $ua)) {
            $browser = 'Chrome ' . $this->majorVersion($m[1]);
        } elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Firefox ' . $this->majorVersion($m[1]);
        } elseif (preg_match('/Version\/([\d.]+).+Safari/i', $ua, $m)) {
            $browser = 'Safari ' . $this->majorVersion($m[1]);
        } elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) {
            $browser = 'Opera ' . $this->majorVersion($m[1]);
        }

        // OS
        $os = 'Otro';
        if (preg_match('/Windows NT 10/i', $ua)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            $os = 'Windows ' . $m[1];
        } elseif (preg_match('/Mac OS X ([\d_.]+)/i', $ua, $m)) {
            $os = 'macOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            $os = 'Android ' . $this->majorVersion($m[1]);
        } elseif (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
            $os = 'iOS ' . str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }

    private function majorVersion(string $v): string
    {
        return explode('.', $v)[0] ?? $v;
    }

    private function extractBotName(string $ua): string
    {
        $known = ['Googlebot', 'Bingbot', 'ClaudeBot', 'GPTBot', 'PerplexityBot', 'Slackbot', 'WhatsApp', 'Twitterbot', 'facebookexternalhit'];
        foreach ($known as $bot) {
            if (stripos($ua, $bot) !== false) {
                return $bot;
            }
        }
        return 'bot';
    }

    /**
     * Trunca IP para privacy:
     *  - IPv4: 192.168.1.45 → 192.168.1.0
     *  - IPv6: trunca a /48
     */
    public function truncateIp(string $ip): ?string
    {
        if ($ip === '' || $ip === '0.0.0.0') return null;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // /48 — primeros 3 hextets, resto en 0
            $exp = inet_ntop(inet_pton($ip));
            if (!$exp) return null;
            $parts = explode(':', $exp);
            $kept = array_slice($parts, 0, 3);
            return implode(':', $kept) . '::';
        }
        return null;
    }

    /**
     * Geolocaliza IP via ip-api.com (free tier, 45 req/min, sin API key).
     * Cachea 24h. Si falla, retorna null silenciosamente.
     *
     * @return array{country:string, country_name:string}|null
     */
    public function geolocateIp(string $ip): ?array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // privadas / loopback — no geolocates
        }
        $cacheKey = 'wlink.geo.' . $ip;
        return Cache::remember($cacheKey, 86400, function () use ($ip) {
            try {
                $r = Http::timeout(2)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,country,countryCode',
                ]);
                if (!$r->ok()) return null;
                $body = $r->json();
                if (($body['status'] ?? '') !== 'success') return null;
                return [
                    'country' => $body['countryCode'] ?? null,
                    'country_name' => $body['country'] ?? null,
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });
    }
}
