@php
    use Illuminate\Support\Carbon;

    $link = $getRecord();

    // Datasets — TODOS los clicks (incluye bots, pruebas, humanos genuinos)
    $allClicks = $link->clicks()->orderByDesc('created_at')->get();
    $totalClicks = $link->clicks_count;

    // Stats temporales sobre TODO (counter total = lo que se ve grande)
    $now = now();
    $clicksLast7d = $allClicks->where('created_at', '>=', $now->copy()->subDays(7))->count();
    $clicksLast24h = $allClicks->where('created_at', '>=', $now->copy()->subDay())->count();
    $paisesUnicos = $allClicks->pluck('country_name')->filter()->unique()->count();
    $browserUnicos = $allClicks->pluck('browser')->filter()->unique()->count();

    // Split informativo (para mostrar en banner aclaratorio, no afecta el counter)
    $totalBots = $allClicks->where('device', '=', 'bot')->count();
    $totalHumanos = $allClicks->where('device', '!=', 'bot')->count();

    // Serie temporal últimos 30 días
    $serie = [];
    for ($i = 29; $i >= 0; $i--) {
        $dia = $now->copy()->subDays($i);
        $diaStr = $dia->format('Y-m-d');
        $count = $allClicks->filter(fn($c) => $c->created_at?->format('Y-m-d') === $diaStr)->count();
        $serie[] = [
            'fecha' => $diaStr,
            'fecha_label' => $dia->format('d/m'),
            'count' => $count,
        ];
    }
    $maxSerie = max(array_column($serie, 'count')) ?: 1;

    // Top países
    $porPais = $allClicks->groupBy('country_name')->map->count()->sortDesc();
    $maxPais = $porPais->first() ?: 1;

    // Distribución device
    $porDevice = $allClicks->groupBy('device')->map->count()->sortDesc();

    // Top browsers
    $porBrowser = $allClicks->groupBy('browser')->map->count()->sortDesc()->take(5);

    // Top referrers
    $porReferer = $allClicks->groupBy('referer_host')->map->count()->sortDesc()->take(10);

    // Últimos 20 clicks
    $clicksRecientes = $allClicks->take(20);

    // Helpers de color
    $deviceColors = ['mobile' => '#0066ff', 'desktop' => '#10b981', 'tablet' => '#f59e0b', 'bot' => '#9ca3af', 'unknown' => '#d1d5db'];
@endphp

@if($allClicks->isEmpty())
    <div style="padding: 28px; background: #f9fafb; border-radius: 8px; color: #6b7280; text-align: center; font-size: 0.95rem;">
        <div style="font-size: 2rem; margin-bottom: 8px;">📊</div>
        Todavía no hay clicks registrados sobre este enlace.<br>
        <span style="font-size: 0.8rem;">Compartí el enlace corto y volvé a esta pantalla — las estadísticas aparecen acá automáticamente.</span>
    </div>
@else

    {{-- Header tipo Bitly Track --}}
    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 18px;">
        <div>
            <div style="font-size: 1.15rem; font-weight: 800; color: #1f2937;">Resumen</div>
            <div style="font-size: 0.78rem; color: #6b7280;">Estadísticas históricas del enlace <strong>/l/{{ $link->slug }}</strong>.</div>
        </div>
        <div style="padding: 6px 12px; background: #f3f4f6; border-radius: 6px; font-size: 0.78rem; color: #4b5563;">
            📅 Rango: <strong>Histórico completo</strong>
        </div>
    </div>

    {{-- ==================== STAT CARDS (estilo Bitly Track) ==================== --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 24px;">
        <div style="padding: 18px 20px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.04);">
            <div style="font-size: 0.78rem; color: #6b7280; font-weight: 600; margin-bottom: 4px;">Clicks totales</div>
            <div style="font-size: 2.4rem; font-weight: 800; color: #1f2937; line-height: 1; letter-spacing: -0.02em;">{{ number_format($totalClicks) }}</div>
            <div style="font-size: 0.72rem; color: #9ca3af; margin-top: 4px;">Cada vez que abren <span style="font-family: monospace;">/l/{{ $link->slug }}</span></div>
        </div>
        <div style="padding: 18px 20px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.04);">
            <div style="font-size: 0.78rem; color: #6b7280; font-weight: 600; margin-bottom: 4px;">Últimas 24 horas</div>
            <div style="font-size: 2.4rem; font-weight: 800; color: #15803d; line-height: 1; letter-spacing: -0.02em;">{{ $clicksLast24h }}</div>
            <div style="font-size: 0.72rem; color: #9ca3af; margin-top: 4px;">clicks recibidos hoy</div>
        </div>
        <div style="padding: 18px 20px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.04);">
            <div style="font-size: 0.78rem; color: #6b7280; font-weight: 600; margin-bottom: 4px;">Últimos 7 días</div>
            <div style="font-size: 2.4rem; font-weight: 800; color: #1e40af; line-height: 1; letter-spacing: -0.02em;">{{ $clicksLast7d }}</div>
            <div style="font-size: 0.72rem; color: #9ca3af; margin-top: 4px;">en la última semana</div>
        </div>
        <div style="padding: 18px 20px; background: white; border: 1px solid #e5e7eb; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.04);">
            <div style="font-size: 0.78rem; color: #6b7280; font-weight: 600; margin-bottom: 4px;">Países distintos</div>
            <div style="font-size: 2.4rem; font-weight: 800; color: #78350f; line-height: 1; letter-spacing: -0.02em;">{{ $paisesUnicos }}</div>
            <div style="font-size: 0.72rem; color: #9ca3af; margin-top: 4px;">desde dónde se abrió</div>
        </div>
    </div>

    {{-- Aclaración cuando hay bots — el counter SÍ los incluye, lo aclaramos --}}
    @if($totalBots > 0)
        <div style="padding: 10px 14px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 18px; font-size: 0.82rem; color: #4b5563;">
            <strong>ℹ️ Composición de los {{ $totalClicks }} clicks:</strong>
            {{ $totalHumanos }} de personas reales · {{ $totalBots }} de bots (Googlebot/ClaudeBot/Twitterbot rastreando el link cuando lo compartís).
        </div>
    @endif

    {{-- ==================== GRÁFICO TEMPORAL 30 DÍAS ==================== --}}
    <div style="background: #fafbfc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px;">
            <div>
                <div style="font-weight: 700; color: #1f2937; font-size: 1rem;">Clicks por día — últimos 30 días</div>
                <div style="font-size: 0.78rem; color: #6b7280;">Cada barra es un día. Pasá el mouse para ver el detalle.</div>
            </div>
            <div style="font-size: 0.75rem; color: #9ca3af;">
                Pico: <strong style="color: #0066ff;">{{ $maxSerie }}</strong> clicks/día
            </div>
        </div>

        <div style="display: flex; align-items: flex-end; gap: 2px; height: 110px; padding: 0 4px;">
            @foreach($serie as $i => $punto)
                @php
                    $altura = $maxSerie > 0 ? ($punto['count'] / $maxSerie) * 100 : 0;
                    $altura = max($punto['count'] > 0 ? 4 : 1, $altura); // mínimo 4% si tiene clicks
                    $hoy = $i === 29;
                    $color = $punto['count'] === 0
                        ? '#e5e7eb'
                        : ($hoy ? '#0044bb' : ($punto['count'] === $maxSerie ? '#10b981' : '#0066ff'));
                @endphp
                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;"
                     title="{{ $punto['fecha_label'] }}: {{ $punto['count'] }} click{{ $punto['count'] !== 1 ? 's' : '' }}">
                    <div style="
                        width: 100%;
                        height: {{ $altura }}%;
                        min-height: 2px;
                        background: {{ $color }};
                        border-radius: 2px 2px 0 0;
                        transition: opacity 0.15s;
                    "
                    onmouseover="this.style.opacity=0.7" onmouseout="this.style.opacity=1"></div>
                </div>
            @endforeach
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.7rem; color: #9ca3af;">
            <span>{{ $serie[0]['fecha_label'] }}</span>
            <span>{{ $serie[14]['fecha_label'] }}</span>
            <span>Hoy ({{ $serie[29]['fecha_label'] }})</span>
        </div>
    </div>

    {{-- ==================== GRID 2 COLUMNAS: PAÍSES + DEVICES ==================== --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">

        {{-- TOP PAÍSES --}}
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px;">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">🌍 Desde qué países se abre</div>
            <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">Top 10 países que más visitaron este enlace</div>
            @forelse($porPais->take(10) as $pais => $count)
                @php $pct = $maxPais > 0 ? ($count / $maxPais) * 100 : 0; @endphp
                <div style="margin-bottom: 8px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 3px;">
                        <span style="color: #1f2937;">{{ $pais ?: '— sin geo —' }}</span>
                        <span style="color: #6b7280; font-variant-numeric: tabular-nums;">{{ $count }}</span>
                    </div>
                    <div style="height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden;">
                        <div style="width: {{ $pct }}%; height: 100%; background: linear-gradient(90deg, #0066ff, #4d8bff); border-radius: 3px;"></div>
                    </div>
                </div>
            @empty
                <div style="color: #9ca3af; font-style: italic; font-size: 0.85rem;">Sin datos de geolocalización todavía.</div>
            @endforelse
        </div>

        {{-- DEVICES + BROWSERS --}}
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px;">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">📱 Tipo de dispositivo</div>
            <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">Con qué abrieron el enlace</div>

            <div style="display: flex; gap: 8px; height: 28px; border-radius: 6px; overflow: hidden; margin-bottom: 12px; background: #f3f4f6;">
                @foreach($porDevice as $device => $count)
                    @php $pct = $totalClicks > 0 ? ($count / $totalClicks) * 100 : 0; @endphp
                    <div style="
                        width: {{ $pct }}%;
                        background: {{ $deviceColors[$device] ?? '#9ca3af' }};
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        color: white;
                        font-size: 0.75rem;
                        font-weight: 700;
                        min-width: 12px;
                    " title="{{ ucfirst($device) }}: {{ $count }} ({{ round($pct) }}%)">
                        @if($pct > 12){{ round($pct) }}%@endif
                    </div>
                @endforeach
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.82rem; margin-bottom: 18px;">
                @foreach($porDevice as $device => $count)
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <div style="width: 10px; height: 10px; border-radius: 2px; background: {{ $deviceColors[$device] ?? '#9ca3af' }};"></div>
                        <span style="color: #4b5563;">{{ ucfirst($device ?: '—') }}</span>
                        <strong style="color: #1f2937;">{{ $count }}</strong>
                    </div>
                @endforeach
            </div>

            <div style="font-weight: 700; color: #1f2937; font-size: 0.85rem; margin-bottom: 6px;">🌐 Top navegadores</div>
            @forelse($porBrowser as $browser => $count)
                <div style="display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.82rem; border-bottom: 1px solid #f3f4f6;">
                    <span style="color: #4b5563;">{{ $browser ?: '—' }}</span>
                    <span style="color: #1f2937; font-weight: 600;">{{ $count }}</span>
                </div>
            @empty
                <div style="color: #9ca3af; font-style: italic; font-size: 0.85rem;">—</div>
            @endforelse
        </div>

    </div>

    {{-- ==================== REFERRERS ==================== --}}
    @if($porReferer->isNotEmpty())
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px; margin-bottom: 24px;">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">🔗 Desde qué sitios mandan tráfico</div>
            <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">Cuando alguien hace click desde otro sitio, el navegador nos dice cuál era. "directo" = pegaron el enlace sin venir de ningún lado (WhatsApp, email, escribir la URL a mano).</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                        <th style="text-align: left; padding: 6px 10px; font-weight: 600; color: #374151;">Sitio de origen</th>
                        <th style="text-align: right; padding: 6px 10px; font-weight: 600; color: #374151;">Clicks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($porReferer as $ref => $count)
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 6px 10px; color: #1f2937;">
                                {{ $ref ?: '(directo — sin referrer)' }}
                            </td>
                            <td style="padding: 6px 10px; text-align: right; color: #1f2937; font-weight: 600;">{{ $count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ==================== LISTA DETALLADA ÚLTIMOS 20 CLICKS ==================== --}}
    <details style="background: #fafbfc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px;">
        <summary style="cursor: pointer; font-weight: 700; color: #1f2937; font-size: 0.9rem;">
            Ver detalle de los últimos {{ min(20, $clicksRecientes->count()) }} clicks (uno por uno)
        </summary>
        <div style="overflow-x: auto; margin-top: 14px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: #f3f4f6; border-bottom: 1px solid #e5e7eb;">
                        <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: #374151;">Fecha</th>
                        <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: #374151;">País</th>
                        <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: #374151;">Device</th>
                        <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: #374151;">Navegador</th>
                        <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: #374151;">Sist. operativo</th>
                        <th style="text-align: left; padding: 6px 8px; font-weight: 600; color: #374151;">Origen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($clicksRecientes as $c)
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 6px 8px; color: #4b5563; white-space: nowrap;">{{ $c->created_at?->format('d/m/Y H:i') }}</td>
                            <td style="padding: 6px 8px;">{{ $c->country_name ?: '—' }}</td>
                            <td style="padding: 6px 8px;">{{ ucfirst($c->device ?: '—') }}</td>
                            <td style="padding: 6px 8px;">{{ $c->browser ?: '—' }}</td>
                            <td style="padding: 6px 8px;">{{ $c->os ?: '—' }}</td>
                            <td style="padding: 6px 8px; color: #6b7280;">{{ $c->referer_host ?: 'directo' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="margin-top: 10px; font-size: 0.72rem; color: #9ca3af;">
            * La IP se guarda truncada (/24) por privacy. No vemos la IP completa del visitante.
        </div>
    </details>
@endif
