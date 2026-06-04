<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * Policy de autorización para el modelo Account (Fase 2).
 *
 * Nota importante sobre Gate::before (AppServiceProvider):
 * El Gate::before en AppServiceProvider retorna true para super_admin,
 * lo que hace que super_admin bypass TODAS las policies sin llamarlas.
 * Por eso los métodos de esta Policy no necesitan verificar isSuperAdmin().
 *
 * Roles y permisos:
 *  - super_admin  : bypass vía Gate::before, nunca llega a esta Policy.
 *  - supervisor   : ve y gestiona solo sus propias cuentas (supervisor_id = $actor->id).
 *  - jefe         : ve las cuentas que tiene asignadas (pivot); NO puede crear.
 *  - creador      : sin acceso al ResourceManager (canViewAny = false).
 */
class AccountPolicy
{
    /**
     * ¿Puede ver la lista de cuentas?
     * supervisor y jefe sí (con scope en getEloquentQuery).
     * creador: no.
     */
    public function viewAny(User $user): bool
    {
        return ! $user->isCreador();
    }

    /**
     * ¿Puede ver una cuenta específica?
     * supervisor: solo las suyas.
     * jefe: solo las que tiene asignadas.
     * creador: no.
     */
    public function view(User $user, Account $account): bool
    {
        if ($user->isSupervisor()) {
            return $account->supervisor_id === $user->id;
        }

        if ($user->isJefe()) {
            return $user->accounts()->where('accounts.id', $account->id)->exists();
        }

        return false;
    }

    /**
     * ¿Puede crear cuentas?
     * Solo super_admin (bypass) y supervisor.
     * jefe y creador: no.
     */
    public function create(User $user): bool
    {
        return $user->isSupervisor();
    }

    /**
     * ¿Puede editar una cuenta?
     * supervisor: solo sus propias cuentas.
     * jefe y creador: no.
     */
    public function update(User $user, Account $account): bool
    {
        if ($user->isSupervisor()) {
            return $account->supervisor_id === $user->id;
        }

        return false;
    }

    /**
     * ¿Puede eliminar una cuenta?
     * Solo supervisor dueña de la cuenta.
     * Nota: las cuentas no se eliminan físicamente en V1 (is_active=false).
     * Esta policy sirve como guardia si se expone la acción en UI.
     */
    public function delete(User $user, Account $account): bool
    {
        if ($user->isSupervisor()) {
            return $account->supervisor_id === $user->id;
        }

        return false;
    }

    /**
     * ¿Puede el actor asignar la cuenta a un usuario target?
     *
     * Reglas:
     *  - super_admin: siempre (bypass por Gate::before, nunca llega aquí).
     *  - supervisor: si es dueña de la cuenta Y supervisa al target.
     *  - jefe: si tiene la cuenta asignada Y supervisa al target (solo sus creadores directos).
     *  - creador: nunca.
     *
     * @param User    $user    El actor que intenta asignar.
     * @param Account $account La cuenta a asignar.
     * @param User    $target  El usuario que recibirá la cuenta.
     */
    public function assignToUser(User $user, Account $account, User $target): bool
    {
        if ($user->isSupervisor()) {
            return $account->supervisor_id === $user->id
                && $user->supervises($target);
        }

        if ($user->isJefe()) {
            return $user->accounts()->where('accounts.id', $account->id)->exists()
                && $user->supervises($target);
        }

        return false;
    }
}
