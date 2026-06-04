<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Services\ShortLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller PÚBLICO (sin auth) que resuelve /l/{slug} → redirect 302 al destino.
 *
 * NFR del brief: <200ms p95. Para cumplirlo:
 *  - Query con scope active() + ->first() (1 query SQLite por slug).
 *  - tracking del click via service AL VUELO (sincronico hoy; si se vuelve cuello
 *    de botella, mover a queued job para no bloquear el redirect).
 *  - 404 con vista propia con branding Webtilia.
 */
class LinkRedirectController extends Controller
{
    public function __construct(private readonly ShortLinkService $service) {}

    public function __invoke(Request $request, string $slug): RedirectResponse|Response
    {
        $link = Link::where('slug', $slug)->first();

        if (!$link) {
            return $this->notFoundView('No encontramos ningún enlace con ese código.');
        }

        if (!$link->is_active) {
            return $this->notFoundView('Este enlace fue desactivado por el equipo.');
        }

        if ($link->expires_at && $link->expires_at->isPast()) {
            return $this->notFoundView('Este enlace expiró el ' . $link->expires_at->format('d/m/Y') . '.');
        }

        // Tracking — NO bloquea el redirect si falla
        $this->service->recordClick($link, $request);

        $status = (int) config('wlink.redirect_status', 302);
        return redirect()->away($link->destination_url, $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Robots-Tag' => 'noindex', // Google no debería indexar el redirect
        ]);
    }

    private function notFoundView(string $razon): Response
    {
        return response()->view('wlink.not-found', [
            'razon' => $razon,
        ], 404);
    }
}
