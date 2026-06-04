<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\PageButton;
use App\Models\PageView;
use App\Services\ShortLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Renderer PÚBLICO (sin auth) de las Páginas WLink.
 *
 * Endpoints:
 *  - GET  /p/{slug}                → renderiza la mini-landing HTML responsive
 *  - GET  /p/{slug}/c/{buttonId}   → trackea click en botón + redirige al destino
 *
 * Mobile-first, sin JS de Filament, sin auth. Si la Page no existe o está
 * inactiva, devuelve 404 con branding Webtilia.
 */
class PageRenderController extends Controller
{
    public function __construct(private readonly ShortLinkService $service) {}

    public function show(Request $request, string $slug): Response
    {
        $page = Page::where('slug', $slug)->first();

        if (!$page || !$page->is_active) {
            return $this->notFoundView('Esta página no existe o fue desactivada.');
        }

        // Track de la visita ANTES del render (no bloquea si falla)
        $this->recordView($page, $request);

        $buttons = $page->buttons()->active()->orderBy('order')->get();
        $design = $page->designTokens();

        return response()->view('wlink.page-render', [
            'page' => $page,
            'buttons' => $buttons,
            'design' => $design,
        ]);
    }

    /**
     * Click en un botón de la Page. Trackea engagement + redirige.
     * Si el botón apunta a un Link interno, la redirección es a /l/{slug}
     * (así el counter del Link también suma — single source of truth).
     */
    public function clickButton(Request $request, string $slug, int $buttonId): RedirectResponse|Response
    {
        $page = Page::where('slug', $slug)->first();
        if (!$page || !$page->is_active) {
            return $this->notFoundView('La página de origen no está disponible.');
        }

        $button = PageButton::where('id', $buttonId)
            ->where('page_id', $page->id)
            ->first();

        if (!$button || !$button->is_active) {
            return $this->notFoundView('Este botón no está disponible.');
        }

        $destination = $button->resolveDestination();
        if (!$destination) {
            return $this->notFoundView('El destino de este botón no es válido (link interno desactivado o sin URL).');
        }

        // Trackeo engagement del botón
        try {
            $button->incrementEngagements();
        } catch (\Throwable $e) {
            Log::warning('Page button incrementEngagements fallo: ' . $e->getMessage());
        }

        $status = (int) config('wlink.redirect_status', 302);
        return redirect()->away($destination, $status, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Registra una visita a la Page. NO debe romper el render si falla. */
    private function recordView(Page $page, Request $request): void
    {
        try {
            $ua = (string) $request->header('User-Agent', '');
            $parsed = $this->service->parseUserAgent($ua);

            $rawIp = $request->ip() ?? '';
            $truncatedIp = $this->service->truncateIp($rawIp);
            $geo = $rawIp ? $this->service->geolocateIp($rawIp) : null;

            $refererHost = null;
            $referer = $request->header('Referer');
            if ($referer) {
                $refererHost = parse_url($referer, PHP_URL_HOST) ?: null;
            }

            PageView::create([
                'page_id' => $page->id,
                'ip_truncated' => $truncatedIp,
                'country' => $geo['country'] ?? null,
                'country_name' => $geo['country_name'] ?? null,
                'device' => $parsed['device'],
                'browser' => $parsed['browser'],
                'os' => $parsed['os'],
                'referer_host' => $refererHost,
                'user_agent' => Str::limit($ua, 500, ''),
            ]);

            $page->incrementViews();
        } catch (\Throwable $e) {
            Log::warning('Page recordView fallo: ' . $e->getMessage());
        }
    }

    private function notFoundView(string $razon): Response
    {
        return response()->view('wlink.not-found', [
            'razon' => $razon,
        ], 404);
    }
}
