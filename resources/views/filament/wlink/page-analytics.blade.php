@php
    $page = $getRecord();

    $allViews = $page->views()->orderByDesc('created_at')->get();
    $totalViews = $page->views_count;
    $totalEngagements = $page->totalEngagements();
    $ctr = $totalViews > 0 ? round(($totalEngagements / $totalViews) * 100, 1) : 0;

    $now = now();
    $viewsLast24h = $allViews->where('created_at', '>=', $now->copy()->subDay())->count();
    $viewsLast7d  = $allViews->where('created_at', '>=', $now->copy()->subDays(7))->count();
    $paisesUnicos = $allViews->pluck('country_name')->filter()->unique()->count();

    // Serie temporal últimos 30 días
    $serie = [];
    for ($i = 29; $i >= 0; $i--) {
        $dia = $now->copy()->subDays($i);
        $diaStr = $dia->format('Y-m-d');
        $count = $allViews->filter(fn($v) => $v->created_at?->format('Y-m-d') === $diaStr)->count();
        $serie[] = [
            'fecha_label' => $dia->format('d/m'),
            'count' => $count,
        ];
    }
    $maxSerie = max(array_column($serie, 'count')) ?: 1;

    $porPais = $allViews->groupBy('country_name')->map->count()->sortDesc();
    $maxPais = $porPais->first() ?: 1;
    $porDevice = $allViews->groupBy('device')->map->count()->sortDesc();
    $porReferer = $allViews->groupBy('referer_host')->map->count()->sortDesc()->take(10);

    // Top botones más clickeados de la página
    $topBotones = $page->buttons()->orderByDesc('engagements_count')->get();
    $maxBoton = $topBotones->first()?->engagements_count ?: 1;

    $deviceColors = ['mobile' => '#0066ff', 'desktop' => '#10b981', 'tablet' => '#f59e0b', 'bot' => '#9ca3af', 'unknown' => '#d1d5db'];
@endphp

@if($allViews->isEmpty())
    <div style="padding: 28px; background: #f9fafb; border-radius: 8px; color: #6b7280; text-align: center; font-size: 0.95rem;">
        <div style="font-size: 2rem; margin-bottom: 8px;">📊</div>
        Todavía no hay visitas a esta página.<br>
        <span style="font-size: 0.8rem;">Compartí <strong>{{ $page->publicUrl() }}</strong> y las visitas aparecen acá automáticamente.</span>
    </div>
@else

    {{-- ==================== STAT CARDS ==================== --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 24px;">
        <div style="padding: 14px 16px; background: linear-gradient(135deg, #0066ff 0%, #0044bb 100%); color: white; border-radius: 8px;">
            <div style="font-size: 0.7rem; opacity: 0.85; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Visitas totales</div>
            <div style="font-size: 1.9rem; font-weight: 800; line-height: 1.1;">{{ number_format($totalViews) }}</div>
            <div style="font-size: 0.75rem; opacity: 0.85;">Personas que abrieron la página</div>
        </div>
        <div style="padding: 14px 16px; background: linear-gradient(135deg, #10b981 0%, #047857 100%); color: white; border-radius: 8px;">
            <div style="font-size: 0.7rem; opacity: 0.85; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Interacciones</div>
            <div style="font-size: 1.9rem; font-weight: 800; line-height: 1.1;">{{ number_format($totalEngagements) }}</div>
            <div style="font-size: 0.75rem; opacity: 0.85;">Clicks a los botones</div>
        </div>
        <div style="padding: 14px 16px; background: #fef3c7; border-radius: 8px; border: 1px solid #fde68a;">
            <div style="font-size: 0.7rem; color: #92400e; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Tasa de interacción (CTR)</div>
            <div style="font-size: 1.9rem; font-weight: 800; color: #78350f; line-height: 1.1;">{{ $ctr }}%</div>
            <div style="font-size: 0.75rem; color: #92400e;">% de visitantes que clickearon algún botón</div>
        </div>
        <div style="padding: 14px 16px; background: #eff6ff; border-radius: 8px; border: 1px solid #bfdbfe;">
            <div style="font-size: 0.7rem; color: #1e40af; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Países distintos</div>
            <div style="font-size: 1.9rem; font-weight: 800; color: #1e3a8a; line-height: 1.1;">{{ $paisesUnicos }}</div>
            <div style="font-size: 0.75rem; color: #1e40af;">desde dónde se vio</div>
        </div>
    </div>

    {{-- ==================== GRÁFICO VISITAS POR DÍA 30 DÍAS ==================== --}}
    <div style="background: #fafbfc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px; margin-bottom: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px;">
            <div>
                <div style="font-weight: 700; color: #1f2937; font-size: 1rem;">Visitas a la página por día — últimos 30 días</div>
                <div style="font-size: 0.78rem; color: #6b7280;">Cada barra es un día.</div>
            </div>
            <div style="font-size: 0.75rem; color: #9ca3af;">Pico: <strong style="color: #0066ff;">{{ $maxSerie }}</strong> visitas/día</div>
        </div>
        <div style="display: flex; align-items: flex-end; gap: 2px; height: 110px; padding: 0 4px;">
            @foreach($serie as $i => $punto)
                @php
                    $altura = $maxSerie > 0 ? ($punto['count'] / $maxSerie) * 100 : 0;
                    $altura = max($punto['count'] > 0 ? 4 : 1, $altura);
                    $hoy = $i === 29;
                    $color = $punto['count'] === 0 ? '#e5e7eb' : ($hoy ? '#0044bb' : ($punto['count'] === $maxSerie ? '#10b981' : '#0066ff'));
                @endphp
                <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px;"
                     title="{{ $punto['fecha_label'] }}: {{ $punto['count'] }} visita{{ $punto['count'] !== 1 ? 's' : '' }}">
                    <div style="width: 100%; height: {{ $altura }}%; min-height: 2px; background: {{ $color }}; border-radius: 2px 2px 0 0;"></div>
                </div>
            @endforeach
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 0.7rem; color: #9ca3af;">
            <span>{{ $serie[0]['fecha_label'] }}</span>
            <span>{{ $serie[14]['fecha_label'] }}</span>
            <span>Hoy ({{ $serie[29]['fecha_label'] }})</span>
        </div>
    </div>

    {{-- ==================== TOP BOTONES MÁS CLICKEADOS ==================== --}}
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px; margin-bottom: 24px;">
        <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">🏆 Botones más clickeados</div>
        <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">Qué botones de tu página están funcionando mejor</div>
        @forelse($topBotones as $btn)
            @php $pct = $maxBoton > 0 ? ($btn->engagements_count / $maxBoton) * 100 : 0; @endphp
            <div style="margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 3px;">
                    <span style="color: #1f2937;">{{ $btn->icon }} {{ $btn->label }}</span>
                    <span style="color: #6b7280; font-variant-numeric: tabular-nums;"><strong>{{ $btn->engagements_count }}</strong> clicks</span>
                </div>
                <div style="height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden;">
                    <div style="width: {{ $pct }}%; height: 100%; background: linear-gradient(90deg, #10b981, #34d399); border-radius: 3px;"></div>
                </div>
            </div>
        @empty
            <div style="color: #9ca3af; font-style: italic; font-size: 0.85rem;">Esta página no tiene botones todavía.</div>
        @endforelse
    </div>

    {{-- ==================== PAÍSES + DEVICES + REFERRERS ==================== --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 24px;">

        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px;">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">🌍 Desde qué países la abrieron</div>
            <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">Top 10 países</div>
            @forelse($porPais->take(10) as $pais => $count)
                @php $pct = $maxPais > 0 ? ($count / $maxPais) * 100 : 0; @endphp
                <div style="margin-bottom: 8px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 3px;">
                        <span style="color: #1f2937;">{{ $pais ?: '— sin geo —' }}</span>
                        <span style="color: #6b7280;">{{ $count }}</span>
                    </div>
                    <div style="height: 6px; background: #f3f4f6; border-radius: 3px; overflow: hidden;">
                        <div style="width: {{ $pct }}%; height: 100%; background: linear-gradient(90deg, #0066ff, #4d8bff); border-radius: 3px;"></div>
                    </div>
                </div>
            @empty
                <div style="color: #9ca3af; font-style: italic; font-size: 0.85rem;">Sin datos.</div>
            @endforelse
        </div>

        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px;">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">📱 Tipo de dispositivo</div>
            <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">Cómo accedieron a la página</div>
            <div style="display: flex; gap: 4px; height: 28px; border-radius: 6px; overflow: hidden; margin-bottom: 12px; background: #f3f4f6;">
                @foreach($porDevice as $device => $count)
                    @php $pct = $totalViews > 0 ? ($count / $totalViews) * 100 : 0; @endphp
                    <div style="width: {{ $pct }}%; background: {{ $deviceColors[$device] ?? '#9ca3af' }}; display: flex; align-items: center; justify-content: center; color: white; font-size: 0.75rem; font-weight: 700; min-width: 12px;"
                         title="{{ ucfirst($device) }}: {{ $count }} ({{ round($pct) }}%)">
                        @if($pct > 12){{ round($pct) }}%@endif
                    </div>
                @endforeach
            </div>
            <div style="display: flex; flex-wrap: wrap; gap: 12px; font-size: 0.82rem;">
                @foreach($porDevice as $device => $count)
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <div style="width: 10px; height: 10px; border-radius: 2px; background: {{ $deviceColors[$device] ?? '#9ca3af' }};"></div>
                        <span style="color: #4b5563;">{{ ucfirst($device ?: '—') }}</span>
                        <strong style="color: #1f2937;">{{ $count }}</strong>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @if($porReferer->isNotEmpty())
        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 18px; margin-bottom: 24px;">
            <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem; margin-bottom: 4px;">🔗 Desde qué sitios llegaron a la página</div>
            <div style="font-size: 0.78rem; color: #6b7280; margin-bottom: 14px;">"directo" = abrieron la URL sin venir de ningún sitio (WhatsApp, Instagram bio, email, escribir la URL).</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                        <th style="text-align: left; padding: 6px 10px; font-weight: 600; color: #374151;">Sitio de origen</th>
                        <th style="text-align: right; padding: 6px 10px; font-weight: 600; color: #374151;">Visitas</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($porReferer as $ref => $count)
                        <tr style="border-bottom: 1px solid #f3f4f6;">
                            <td style="padding: 6px 10px; color: #1f2937;">{{ $ref ?: '(directo — sin referrer)' }}</td>
                            <td style="padding: 6px 10px; text-align: right; color: #1f2937; font-weight: 600;">{{ $count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endif
