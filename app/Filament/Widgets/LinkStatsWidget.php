<?php

namespace App\Filament\Widgets;

use App\Models\Link;
use App\Models\LinkClick;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Widget de stats por rama — visible en el Dashboard del admin CortarLink.
 *
 * 4 KPIs:
 *  - Total clicks acumulados (según rama del user autenticado)
 *  - Clicks últimas 24h
 *  - Enlaces activos
 *  - Top enlace de los últimos 7 días (slug + clicks)
 *
 * Fase 3: las queries se filtran por branchUserIds() del user autenticado.
 * super_admin sigue viendo stats globales (branchUserIds = todos).
 */
class LinkStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 5;
    protected ?string $heading = 'Estadísticas generales';
    protected ?string $description = 'Resumen de los enlaces cortos de tu rama';

    protected function getStats(): array
    {
        $user = Auth::user();

        // IDs de usuarios de la rama del user autenticado.
        // Para super_admin devuelve todos (sin cambio en el comportamiento global).
        $branchIds = $user ? $user->branchUserIds()->all() : [];

        // ---------------------------------------------------------------
        // clicks_count = denorm total por link — suma filtrada por rama
        // ---------------------------------------------------------------
        $totalClicks = (int) Link::whereIn('created_by', $branchIds)->sum('clicks_count');

        // ---------------------------------------------------------------
        // Clicks en ventanas de tiempo — via link_clicks filtrado por rama
        // ---------------------------------------------------------------
        $clicksLast24h = LinkClick::whereHas('link', function ($q) use ($branchIds) {
            $q->whereIn('created_by', $branchIds);
        })->where('created_at', '>=', now()->subDay())->count();

        $clicksLast7d = LinkClick::whereHas('link', function ($q) use ($branchIds) {
            $q->whereIn('created_by', $branchIds);
        })->where('created_at', '>=', now()->subDays(7))->count();

        // ---------------------------------------------------------------
        // Enlaces activos de la rama
        // ---------------------------------------------------------------
        $enlacesActivos = Link::active()->whereIn('created_by', $branchIds)->count();

        // ---------------------------------------------------------------
        // Top enlace por clicks en los últimos 7 días — solo de la rama
        // ---------------------------------------------------------------
        $topLink = Link::query()
            ->select('links.*')
            ->join('link_clicks', 'link_clicks.link_id', '=', 'links.id')
            ->where('link_clicks.created_at', '>=', now()->subDays(7))
            ->whereIn('links.created_by', $branchIds)
            ->selectRaw('COUNT(link_clicks.id) as clicks_7d')
            ->groupBy('links.id')
            ->orderByDesc('clicks_7d')
            ->first();

        // ---------------------------------------------------------------
        // Sparkline últimos 7 días — solo clicks de la rama
        // ---------------------------------------------------------------
        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $dia = now()->subDays($i)->format('Y-m-d');
            $sparkline[] = LinkClick::whereHas('link', function ($q) use ($branchIds) {
                $q->whereIn('created_by', $branchIds);
            })->whereDate('created_at', $dia)->count();
        }

        return [
            Stat::make('Total clicks acumulados', number_format($totalClicks))
                ->description('Suma de clicks de los enlaces de tu rama')
                ->descriptionIcon('heroicon-m-cursor-arrow-rays')
                ->color('primary')
                ->chart($sparkline),

            Stat::make('Clicks últimas 24 horas', $clicksLast24h)
                ->description($clicksLast7d . ' en los últimos 7 días')
                ->descriptionIcon('heroicon-m-clock')
                ->color($clicksLast24h > 0 ? 'success' : 'gray'),

            Stat::make('Enlaces activos', $enlacesActivos)
                ->description('Que pueden recibir clicks ahora mismo')
                ->descriptionIcon('heroicon-m-link')
                ->color('info'),

            $topLink
                ? Stat::make('Top enlace (últimos 7d)', $topLink->slug)
                    ->description(($topLink->clicks_7d ?? 0) . ' clicks · ' . ($topLink->title ?: 'sin descripción'))
                    ->descriptionIcon('heroicon-m-trophy')
                    ->color('warning')
                    ->url(route('filament.admin.resources.links.view', ['record' => $topLink->id]))
                : Stat::make('Top enlace (últimos 7d)', '—')
                    ->description('Sin actividad esta semana')
                    ->color('gray'),
        ];
    }

    /**
     * Solo cuentas @webtilia.com ven este widget (mismo gate que el resto del panel).
     * Si no hay tabla links todavía (migrations pendientes) o el módulo está vacío,
     * igual mostramos el widget con ceros — más informativo que ocultarlo.
     */
    public static function canView(): bool
    {
        try {
            return \Schema::hasTable('links');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
