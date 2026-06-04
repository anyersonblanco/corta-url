<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers de seguridad globales aplicados a todas las respuestas.
 *
 *  - X-Content-Type-Options: nosniff      → bloquea MIME sniffing
 *  - X-Frame-Options: DENY                → bloquea embedding en iframes
 *  - Referrer-Policy: strict-origin-...   → no leakea path en referrers
 *  - Permissions-Policy                   → bloquea APIs sensibles por default
 *  - Strict-Transport-Security (HSTS)     → fuerza HTTPS por 1 año
 *  - X-Robots-Tag                         → deja los redirects /l y /p sin indexar
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Los redirects de acortador NO deben aparecer en Google. Las mini-páginas SÍ
        // pueden indexarse — sobreescriben este header donde aplique.
        $path = $request->path();
        if (str_starts_with($path, 'l/') || str_starts_with($path, 'admin')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        }

        return $response;
    }
}
