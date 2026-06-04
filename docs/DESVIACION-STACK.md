# Desviación documentada del Stack Webtilia — CortarLink

**Proyecto:** CortarLink (acortador URLs + mini-páginas Linktree, app interna Webtilia)
**Dev Owner:** Anyerson Blanco
**Fecha:** 2026-06-04
**Política base:** STACK-Webtilia (Política de Desarrollo WGP, sec 7 "Desviaciones del stack")

## Resumen ejecutivo

CortarLink se mantiene en **Laravel 13 + Filament 4 + MySQL** en vez del stack obligatorio (Next.js 16 + Postgres/Neon + Prisma + NextAuth + shadcn + Vitest + Vercel). Es desviación formal documentada por costo de reescritura desproporcionado vs beneficio funcional cero.

## Diff stack mandato vs CortarLink

| Capa | Política oficial | CortarLink real |
|---|---|---|
| Framework | Next.js 16 + TS strict | Laravel 13 + PHP 8.3 |
| BD | PostgreSQL 15+ (Neon prod) | MySQL (cPanel webs4) |
| ORM | Prisma v7 + adapter-pg | Eloquent |
| Auth | NextAuth v4 | Filament + `canAccessPanel` gate `@webtilia.com` + `is_active` |
| UI | shadcn/ui + Tailwind | Filament 4 (Livewire) |
| Tests | Vitest | PHPUnit (131 tests / 260 asserts verde) |
| Deploy | Vercel (Bryan) | cPanel webs4 (FTPS bridge `_deploy.php` self-destruido) |
| Repo | `github.com/WebtiliaCorp/...` + `master` | `github.com/anyersonblanco/corta-url.git` + `main` (micro-desv) |
| Storage | Vercel Blob | filesystem `storage/` |
| Email | Resend (dominios Webtilia) | N/A (la app no envía emails hoy) |
| Monitoreo | Sentry obligatorio | Pendiente (`sentry/sentry-laravel` post-Fase-6) |
| CI/CD | GitHub Actions | Pendiente (post-cierre roles) |

Micro-desviaciones adicionales:
- Branch principal **`main`** en vez de `master`.
- Repo en cuenta personal `anyersonblanco` en vez de organización `WebtiliaCorp`, **pendiente de transferencia** cuando Bryan provisione el proyecto en la org.

## Justificación

1. **App ya operativa en staging desde 2026-06-04.** `wlink.webs4.wtldevs.com` responde con 131 tests verdes en PHPUnit, módulos Acortador + Mini-páginas + Dashboard widget funcionales end-to-end.
2. **Costo de reescritura: 2-4 semanas full-time.** Equivale a reimplementar acortador + tracking + analytics SVG + Linktree + roles jerárquicos + AccountPolicies en TS/Prisma/NextAuth con migración de datos históricos (links + link_clicks + pages + page_buttons + page_views + users).
3. **Beneficio funcional: cero.** El usuario final (equipo interno Webtilia) no nota la diferencia.
4. **Sin tráfico de cliente final.** App interna que no expone superficie crítica externa.
5. **Riesgo de migración:** corromper histórico de clicks/views en data move Laravel→Postgres.

## Compromisos asumidos

- **Sin compromiso de migración futura.** Revisión del caso solo si la app escala (Q3 2026 — Líder de Priorización decide entonces).
- **Sentry** se agrega como tarea separada post-Fase-6 (paquete `sentry/sentry-laravel`, fuera de la regla cero-deps que aplicaba SOLO al feature de roles).
- **Branding Webtilia completo** se completa en Fase 6 del feature roles: logotipo blanco "webtilia" + amarillo "marketing digital" + tagline "¡Hagámoslo Distinto!" + razón social WEBTILIA MARKETING SOLUTIONS SAC en footer del panel admin.
- **CI/CD GitHub Actions** se propone como backlog post-roles (no se ejecuta sin aprobación previa).
- **Transferencia del repo** a `WebtiliaCorp` cuando Bryan provisione el slot.

## Espacios de firma

| Rol | Nombre | Firma | Fecha |
|---|---|---|---|
| Dev Owner | Anyerson Blanco | _________________ | _________ |
| Líder de Priorización | _________________ | _________________ | _________ |
| Jefe de TI | Bryan | _________________ | _________ |

---

*Este documento se eleva en Gate 1 junto con el Diseño Técnico de la iniciativa "Sistema de roles jerárquicos para CortarLink".*
