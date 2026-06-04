@php
    $tpl = $design['template'] ?? 'clasico';
    $fontFamily = match ($design['font_family'] ?? 'system') {
        'serif' => '"Georgia", "Times New Roman", serif',
        'mono'  => '"Menlo", "Consolas", monospace',
        default => '"Helvetica Neue", Helvetica, Arial, system-ui, sans-serif',
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{{ $page->title }} · WLink</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="index,follow">
<meta name="description" content="{{ $page->description ?: $page->title }}">

{{-- Open Graph para que se vea bien al compartir en WhatsApp/Twitter --}}
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $page->title }}">
<meta property="og:description" content="{{ $page->description ?: 'Mira mis enlaces' }}">
@if($page->avatar_url)
    <meta property="og:image" content="{{ $page->avatar_url }}">
@endif

<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { width: 100%; min-height: 100vh; }
  body {
    font-family: {!! $fontFamily !!};
    background: linear-gradient(165deg, {{ $design['bg_color'] }} 0%, {{ $design['bg_color_to'] }} 100%);
    color: {{ $design['text_color'] }};
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-wrap: pretty;
    padding: 32px 16px;
  }
  .container {
    max-width: 480px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 22px;
  }
  .avatar {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,.4);
    box-shadow: 0 6px 18px rgba(0,0,0,.18);
  }
  .avatar-fallback {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.4rem;
    font-weight: 800;
    background: rgba(255,255,255,.18);
    border: 3px solid rgba(255,255,255,.4);
    color: {{ $design['text_color'] }};
  }
  h1 {
    font-size: 26px;
    font-weight: 800;
    text-align: center;
    text-wrap: balance;
    line-height: 1.18;
  }
  .description {
    font-size: 15px;
    opacity: 0.92;
    text-align: center;
    line-height: 1.5;
    max-width: 380px;
  }
  .buttons {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 8px;
  }
  .btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 14px 18px;
    background: {{ $design['button_bg'] }};
    color: {{ $design['button_text'] }};
    text-decoration: none;
    font-weight: 700;
    font-size: 16px;
    border-radius: {{ $design['button_radius'] }}px;
    border: {{ $design['button_style'] === 'outline' ? '2px solid '.$design['button_bg'] : 'none' }};
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,.10);
    cursor: pointer;
  }
  @if($design['button_style'] === 'outline')
    .btn { background: transparent; color: {{ $design['button_bg'] }}; }
  @elseif($design['button_style'] === 'ghost')
    .btn { background: rgba(255,255,255,.15); color: {{ $design['text_color'] }}; box-shadow: none; backdrop-filter: blur(6px); }
  @endif
  .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,.18); }
  .btn:active { transform: translateY(0); }
  .btn-icon { font-size: 18px; line-height: 1; }
  .btn-label { line-height: 1.3; }

  @if($tpl === 'compacto')
    .btn { padding: 10px 14px; font-size: 14px; }
    .buttons { gap: 8px; }
  @endif

  .footer {
    margin-top: 32px;
    font-size: 11px;
    opacity: 0.6;
    text-align: center;
  }
  .footer a { color: inherit; font-weight: 700; text-decoration: none; }
  .empty {
    margin-top: 24px;
    padding: 20px;
    background: rgba(255,255,255,.12);
    border-radius: 12px;
    text-align: center;
    font-size: 14px;
  }
</style>
</head>
<body>

<main class="container">
    @if($page->avatar_url)
        <img src="{{ $page->avatar_url }}" alt="{{ $page->title }}" class="avatar" loading="eager" decoding="async" width="96" height="96">
    @else
        <div class="avatar-fallback" aria-hidden="true">
            {{ mb_substr(mb_strtoupper($page->title), 0, 1) }}
        </div>
    @endif

    <h1>{{ $page->title }}</h1>

    @if($page->description)
        <p class="description">{{ $page->description }}</p>
    @endif

    @if($buttons->isEmpty())
        <div class="empty">
            🛠️ Esta página todavía no tiene botones.<br>
            <span style="font-size: 12px; opacity: 0.8;">El equipo está configurándola.</span>
        </div>
    @else
        <div class="buttons">
            @foreach($buttons as $button)
                <a href="{{ route('wlink.page.click', ['slug' => $page->slug, 'buttonId' => $button->id]) }}"
                   class="btn"
                   rel="noopener noreferrer">
                    @if($button->displayIcon())
                        <span class="btn-icon" aria-hidden="true">{{ $button->displayIcon() }}</span>
                    @endif
                    <span class="btn-label">{{ $button->label }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="footer">
        Hecho con <a href="https://webtilia.com" target="_blank" rel="noopener">Webtilia · WLink</a>
    </div>
</main>

</body>
</html>
