@php
    $link = $getRecord();
@endphp

<div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap; padding: 12px 0;">
    <img src="{{ $link->qrUrl(280) }}"
         alt="Código QR de {{ $link->slug }}"
         style="width: 200px; height: 200px; border: 1px solid #e5e7eb; border-radius: 8px; flex-shrink: 0;">

    <div style="flex: 1; min-width: 220px;">
        <div style="font-size: 0.85rem; color: #4b5563; margin-bottom: 10px;">
            Cuando alguien escanea este QR con la cámara del celular, va directo al enlace corto y de ahí se redirige al destino.
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="{{ $link->qrUrl(800) }}"
               download="qr-{{ $link->slug }}.png"
               target="_blank"
               style="padding: 8px 16px; background: #0066ff; color: white; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.85rem;">
                Descargar PNG alta resolución
            </a>
            <a href="{{ $link->qrUrl(300) }}"
               target="_blank"
               style="padding: 8px 16px; background: #e5e7eb; color: #1f2937; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.85rem;">
                Abrir QR en otra pestaña
            </a>
        </div>
        <div style="margin-top: 12px; font-size: 0.75rem; color: #9ca3af;">
            El QR se genera con api.qrserver.com (servicio gratuito). Si necesitás un QR custom con logo Webtilia, generálo aparte y reemplazá.
        </div>
    </div>
</div>
