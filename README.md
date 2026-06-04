# CortarLink · Webtilia

Acortador de enlaces propio de Webtilia + mini-páginas estilo Linktree.
Reemplaza Bit.ly para uso interno y de clientes.

## Qué hace

**Acortador de enlaces** — `/l/{slug}`
- Tomá una URL larga, generá un slug corto (auto o personalizado).
- Redirige con HTTP 302 al destino real.
- Trackea cada click: device, browser, OS, país, referrer.
- QR code automático para cada enlace.

**Mini-páginas** (Linktree-style) — `/p/{slug}`
- Mini-landings públicas con múltiples botones.
- Branding personalizable (colores, tipografía, avatar).
- Mobile-first responsive.
- Cada botón puede apuntar a un enlace acortado interno o a URL externa.
- Trackea visitas + interacciones (clicks a botones).

**Admin Filament** — `/admin`
- En español, restringido a cuentas `@webtilia.com`.
- Analytics ricos con charts SVG inline (sin librerías JS extra).
- Widget de estadísticas generales en el escritorio.

## Stack

- PHP 8.3+
- Laravel 13
- Filament 4
- SQLite (default, se puede migrar a MySQL/Postgres)
- 0 librerías JS para charts — todo SVG inline

## Setup local

```bash
git clone https://anyerson_blanco@bitbucket.org/webtilia/cortarlink-web.git
cd cortarlink-web
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Admin local: `http://127.0.0.1:8000/admin/login`
- Email: `ablanco@webtilia.com`
- Pass: `webtilia2026` *(cambiar antes de pasar a producción)*

## Tests

```bash
php artisan test
```

Cobertura actual: 36 tests / 123 asserts (PHPUnit clásico).

## Despliegue (futuro)

Cuando se configure el dominio dedicado (ej. `go.webtilia.com`):

```env
WLINK_SHORT_BASE_URL=https://go.webtilia.com
WLINK_SHORT_PREFIX=
WLINK_PAGES_PREFIX=
```

Los slugs quedan en la raíz: `go.webtilia.com/promo23` y `go.webtilia.com/webtilia`.

## Pendiente (V1.1)

- [ ] Multi-usuario con roles (admin / editor / visor)
- [ ] Password-protect en redirects
- [ ] API REST con tokens
- [ ] Exportar CSV de analytics
- [ ] UTM auto-append (utm_source/medium/campaign)
- [ ] Bulk shortening (varias URLs de una)
- [ ] QR custom con logo Webtilia (lib endroid/qr-code)
- [ ] Dominio dedicado `go.webtilia.com`

## Origen

Extraído del proyecto `scanner-webtilia-panel` el 2026-06-04 como app independiente.
