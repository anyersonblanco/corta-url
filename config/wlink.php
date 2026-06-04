<?php

return [
    /*
    |--------------------------------------------------------------------------
    | URL base para enlaces cortos
    |--------------------------------------------------------------------------
    |
    | Hoy vivimos bajo el host del scanner: scanner.webs4.wtldevs.com/l/{slug}
    | Cuando se configure go.webtilia.com como subdominio dedicado, cambiar a:
    |   WLINK_SHORT_BASE_URL=https://go.webtilia.com
    |   WLINK_SHORT_PREFIX=
    | y el slug queda en la raíz.
    |
    */
    'short_base_url' => env('WLINK_SHORT_BASE_URL', env('APP_URL', 'http://localhost')),
    'short_prefix'   => env('WLINK_SHORT_PREFIX', '/l'),

    /*
    | Prefix de las Páginas WLink (Linktree-style). Distinto del de los Links
    | acortados para evitar colisiones de slugs entre los dos productos.
    */
    'pages_prefix'   => env('WLINK_PAGES_PREFIX', '/p'),

    /*
    | Código HTTP del redirect: 301 = permanente (cacheable por browser),
    | 302 = temporal (no cacheable). Para acortadores normalmente 302 porque
    | el destino puede cambiar (editar link).
    */
    'redirect_status' => (int) env('WLINK_REDIRECT_STATUS', 302),
];
