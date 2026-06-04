<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Link;
use App\Models\LinkDeletionRequest;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\LinkDeletionRequestPolicy;
use App\Policies\LinkPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Fase 1 — Bypass global para super_admin.
         *
         * Gate::before se ejecuta ANTES de cualquier Policy o Gate::define.
         * Retornar `true` desde aquí concede la habilidad sin pasar por la Policy.
         * Retornar `null` (no retornar nada) deja que la Policy resuelva normalmente.
         *
         * Decisión técnica: un único Gate::before centralizado es más simple y seguro
         * que duplicar `if ($user->isSuperAdmin()) return true;` en cada Policy.
         * Cero riesgo de que una Policy nueva olvide el bypass.
         *
         * IMPORTANTE: el bypass solo aplica si el usuario está autenticado (el closure
         * recibe null cuando no hay sesión y Gate::before no se invoca en ese caso).
         */
        // Fase 2 — registrar Policy de Account
        Gate::policy(Account::class, AccountPolicy::class);

        // Fase 4 — registrar Policies de Link y LinkDeletionRequest
        Gate::policy(Link::class, LinkPolicy::class);
        Gate::policy(LinkDeletionRequest::class, LinkDeletionRequestPolicy::class);

        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->isSuperAdmin()) {
                return true;
            }

            return null; // deja que la Policy/Gate específica resuelva
        });
    }
}
