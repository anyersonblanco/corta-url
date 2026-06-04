<div style="text-align: center; padding: 12px 0;">
    <img src="{{ $link->qrUrl(400) }}"
         alt="Código QR de {{ $link->slug }}"
         style="width: 280px; height: 280px; border: 1px solid #e5e7eb; border-radius: 8px; display: inline-block;">

    <div style="margin-top: 16px; font-size: 0.85rem; color: #4b5563;">
        Escanealo con la cámara del celular o descargalo para usarlo en materiales impresos.
    </div>

    <div style="margin-top: 14px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; font-family: monospace; font-size: 0.85rem; color: #1f2937; word-break: break-all;">
        {{ $link->shortUrl() }}
    </div>

    <div style="margin-top: 16px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap;">
        <a href="{{ $link->qrUrl(800) }}"
           download="qr-{{ $link->slug }}.png"
           target="_blank"
           style="padding: 8px 16px; background: #0066ff; color: white; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.85rem;">
            Descargar PNG (800px)
        </a>
        <a href="{{ $link->qrUrl(300) }}"
           target="_blank"
           style="padding: 8px 16px; background: #e5e7eb; color: #1f2937; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 0.85rem;">
            Ver tamaño chico (300px)
        </a>
    </div>
</div>
