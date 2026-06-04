<?php

namespace App\Policies;

use App\Models\Link;
use App\Models\User;

/**
 * Policy de autorización para el modelo Link (Fase 4).
 *
 * Nota sobre Gate::before (AppServiceProvider):
 * El Gate::before retorna true para super_admin, bypassing todas las policies.
 * Por eso los métodos aquí no necesitan verificar isSuperAdmin().
 *
 * Flujo de eliminación:
 *  - delete(): solo super_admin (bypass) puede eliminar directamente.
 *  - requestDeletion(): creador puede solicitar la eliminación de SU propio link.
 *  - Los demás roles (supervisor/jefe) eliminan directamente via DeleteAction.
 */
class LinkPolicy
{
    /**
     * ¿Puede ver la lista de links?
     * Todos los roles con acceso al panel pueden ver la lista (scope de rama en getEloquentQuery).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * ¿Puede ver un link específico?
     */
    public function view(User $user, Link $link): bool
    {
        return true;
    }

    /**
     * ¿Puede crear links?
     * Todos los roles pueden crear.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * ¿Puede editar un link?
     * Todos los roles pueden editar (el scope de rama ya restringe qué ven).
     */
    public function update(User $user, Link $link): bool
    {
        return true;
    }

    /**
     * ¿Puede eliminar directamente un link?
     *
     * Solo super_admin (que bypass por Gate::before).
     * supervisor y jefe también pueden eliminar directamente sin solicitud,
     * pero el bypass de Gate::before no los cubre — se les permite aquí.
     * creador NUNCA puede eliminar directamente; debe solicitar.
     */
    public function delete(User $user, Link $link): bool
    {
        // Gate::before ya manejó super_admin.
        // supervisor y jefe pueden eliminar directamente (tienen autoridad sobre su rama).
        if ($user->isSupervisor() || $user->isJefe()) {
            return $user->canApproveDeletion($link);
        }

        return false; // creador: nunca elimina directamente
    }

    /**
     * ¿Puede solicitar la eliminación de un link?
     *
     * Solo creadores pueden solicitar, y únicamente de sus propios links.
     * Supervisor/jefe/super_admin eliminan directamente, no via solicitud.
     */
    public function requestDeletion(User $user, Link $link): bool
    {
        if ($user->isCreador()) {
            return $link->created_by === $user->id;
        }

        return false;
    }
}
