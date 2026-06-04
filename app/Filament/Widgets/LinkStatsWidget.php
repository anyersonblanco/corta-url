<?php

namespace App\Filament\Widgets;

use App\Models\Link;
use App\Models\LinkClick;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Widget de stats globales — visible en el Dashboard del admin CortarLink.
 *
 * 4 KPIs:
 *  - Total clicks acumulados (todos los enlaces)
 *  - Clicks últimas 24h
 *  - Enlaces activos
 *  - Top enlace de los últimos 7 días (slug + clicks)
 */
class LinkStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 5;
    protected ?string $heading = 'Estadísticas generales';
    protected ?string $description = 'Resumen de todos los enlaces cortos creados por el equipo';

    protected function getStats(): array
    {
        // clicks_count = TODOS los hits a /l/{slug} (humanos + bots)
        $totalClicks = (int) Link::sum('clicks_count');

        $clicksLast24h = LinkClick::where('created_at', '>=', now()->subDay())->count();
        $clicksLast7d = LinkClick::where('created_at', '>=', now()->subDays(7))->count();
        $enlacesActivos = Link::active()->count();

        // Top enlace por clicks en los últimos 7 días
        $topLink = Link::query()
            ->select('links.*')
            ->join('link_clicks', 'link_clicks.link_id', '=', 'links.id')
            ->where('link_clicks.created_at', '>=', now()->subDays(7))
            ->selectRaw('COUNT(link_clicks.id) as clicks_7d')
            ->groupBy('links.id')
            ->orderByDesc('clicks_7d')
            ->first();

        // Sparkline últimos 7 días
        $sparkline = [];
        for ($i = 6; $i >= 0; $i--) {
            $dia = now()->subDays($i)->format('Y-m-d');
            $sparkline[] = LinkClick::whereDate('created_at', $dia)->count();
        }

        return [
            Stat::make('Total clicks acumulados', number_format($totalClicks))
                ->description('Suma de clicks de todos los enlaces cortos')
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
