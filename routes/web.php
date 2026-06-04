<?php

use App\Http\Controllers\LinkRedirectController;
use App\Http\Controllers\PageRenderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Rutas públicas (sin auth) — los slugs son [A-Za-z0-9_-]{2,64} y se validan
// también server-side. Cualquier otro path bajo /l/* o /p/* cae 404 sin tocar BD.
Route::middleware('web')->group(function () {
    // Acortador de enlaces. /l/{slug} → 302 a destination_url
    Route::get('/l/{slug}', LinkRedirectController::class)
        ->where('slug', '[A-Za-z0-9_-]{2,64}')
        ->name('wlink.redirect');

    // Mini-páginas (Linktree-style). /p/{slug} renderiza la mini-landing.
    Route::get('/p/{slug}', [PageRenderController::class, 'show'])
        ->where('slug', '[A-Za-z0-9_-]{2,64}')
        ->name('wlink.page.show');
    Route::get('/p/{slug}/c/{buttonId}', [PageRenderController::class, 'clickButton'])
        ->where(['slug' => '[A-Za-z0-9_-]{2,64}', 'buttonId' => '[0-9]+'])
        ->name('wlink.page.click');
});
