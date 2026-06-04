<?php

namespace App\Policies;

use App\Models\Link;
use App\Models\LinkDeletionRequest;
use App\Models\User;

/**
 * Policy de autorización para LinkDeletionRequest (Fase 4).
 *
 * Nota sobre Gate::before (AppServiceProvider):
 * El Gate::before retorna true para super_admin, bypassing todas las policies.
 *
 * Acceso:
 *  - creador: sin acceso al resource (canViewAny = false).
 *  - supervisor/jefe: pueden ver y revisar solicitudes de su rama.
 */
class LinkDeletionRequestPolicy
{
    /**
     * ¿Puede ver la lista de solicitudes?
     * Creadores no ven el resource; los demás roles sí (con scope de rama en getEloquentQuery).
     */
    public function viewAny(User $user): bool
    {
        return ! $user->isCreador();
    }

    /**
     * ¿Puede ver una solicitud específica?
     * Supervisor/jefe solo si pueden aprobar el link asociado.
     */
    public function view(User $user, LinkDeletionRequest $request): bool
    {
        if ($user->isCreador()) {
            return false;
        }

        $link = $request->link()->withTrashed()->first();
        if (! $link) {
            return false;
        }

        return $user->canApproveDeletion($link);
    }

    /**
     * Nadie puede crear solicitudes desde el resource de Filament.
     * Las solicitudes solo se crean desde la acción en LinkResource.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Nadie puede editar solicitudes directamente.
     */
    public function update(User $user, LinkDeletionRequest $request): bool
    {
        return false;
    }

    /**
     * Nadie puede eliminar solicitudes desde el panel (trazabilidad histórica).
     */
    public function delete(User $user, LinkDeletionRequest $request): bool
    {
        return false;
    }

    /**
     * ¿Puede aprobar una solicitud?
     * Solo si la solicitud está pending Y el actor puede aprobar el link asociado.
     */
    public function approve(User $user, LinkDeletionRequest $request): bool
    {
        if ($request->status !== LinkDeletionRequest::STATUS_PENDING) {
            return false;
        }

        $link = $request->link()->withTrashed()->first();
        if (! $link) {
            return false;
        }

        return $user->canApproveDeletion($link);
    }

    /**
     * ¿Puede rechazar una solicitud?
     * Mismo gate que approve.
     */
    public function reject(User $user, LinkDeletionRequest $request): bool
    {
        return $this->approve($user, $request);
    }
}
