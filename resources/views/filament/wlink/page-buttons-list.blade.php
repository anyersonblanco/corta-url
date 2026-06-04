@php
    $page = $getRecord();
    $buttons = $page->buttons()->orderBy('order')->get();
@endphp

@if($buttons->isEmpty())
    <div style="padding: 20px; background: #f9fafb; border-radius: 6px; color: #6b7280; text-align: center; font-size: 0.9rem;">
        Esta página no tiene botones todavía. <strong>Hacé click en "Editar página"</strong> arriba para agregar.
    </div>
@else
    <div style="display: flex; flex-direction: column; gap: 8px;">
        @foreach($buttons as $btn)
            @php
                $destino = $btn->resolveDestination();
                $tipo = $btn->link_id ? 'enlace interno (' . $btn->link?->slug . ')' : 'URL externa';
            @endphp
            <div style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: {{ $btn->is_active ? '#fafbfc' : '#fee2e2' }}; border: 1px solid {{ $btn->is_active ? '#e5e7eb' : '#fca5a5' }}; border-radius: 8px;">
                @if($btn->icon)
                    <div style="font-size: 1.4rem; line-height: 1; flex-shrink: 0;">{{ $btn->icon }}</div>
                @endif
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #1f2937; font-size: 0.95rem;">{{ $btn->label }}</div>
                    <div style="font-size: 0.78rem; color: #6b7280;">
                        <strong>{{ $tipo }}</strong> →
                        @if($destino)
                            <a href="{{ $destino }}" target="_blank" rel="noopener" style="color: #0066ff;">{{ \Illuminate\Support\Str::limit($destino, 60) }}</a>
                        @else
                            <span style="color: #dc2626;">⚠ sin destino válido</span>
                        @endif
                    </div>
                </div>
                <div style="text-align: right; flex-shrink: 0;">
                    <div style="font-size: 1.2rem; font-weight: 800; color: #059669; line-height: 1;">{{ $btn->engagements_count }}</div>
                    <div style="font-size: 0.7rem; color: #9ca3af;">clicks</div>
                </div>
                @if(!$btn->is_active)
                    <div style="background: #dc2626; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">PAUSADO</div>
                @endif
            </div>
        @endforeach
    </div>
@endif
