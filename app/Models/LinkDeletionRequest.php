<?php

namespace App\Models;

use Database\Factories\LinkDeletionRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitud de eliminación de un Link (Fase 4).
 *
 * Flujo:
 *   1. creador crea la solicitud desde LinkResource (acción "Solicitar eliminación").
 *   2. super_admin / supervisor de rama / jefe directo aprueba o rechaza.
 *   3. Aprobación → SoftDelete del link + status=approved.
 *   4. Rechazo   → link sigue activo  + status=rejected + review_note obligatorio.
 *
 * Anti-race en approve()/reject(): ambos validan status===pending antes de mutar;
 * lanzan \RuntimeException si la solicitud ya fue procesada.
 *
 * @property int         $id
 * @property int         $link_id
 * @property int|null    $requested_by
 * @property string      $reason
 * @property string      $status
 * @property int|null    $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class LinkDeletionRequest extends Model
{
    /** @use HasFactory<LinkDeletionRequestFactory> */
    use HasFactory;

    // =========================================================================
    // Constantes de estado
    // =========================================================================

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    // =========================================================================
    // Configuración del modelo
    // =========================================================================

    protected $fillable = [
        'link_id',
        'requested_by',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // =========================================================================
    // Relaciones
    // =========================================================================

    /**
     * Link al que se refiere esta solicitud.
     * Incluye withTrashed() para que siga visible en el historial
     * aunque el link ya haya sido archivado.
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class)->withTrashed();
    }

    /**
     * Usuario que solicitó la eliminación.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Usuario que revisó la solicitud (aprobó o rechazó).
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_REJECTED);
    }

    // =========================================================================
    // Helpers de aprobación / rechazo
    // =========================================================================

    /**
     * Aprueba la solicitud: soft-delete el link asociado y marca la solicitud.
     *
     * Anti-race: lanza \RuntimeException si status !== pending.
     *
     * @param  User        $reviewer  El usuario que aprueba.
     * @param  string|null $note      Nota opcional de aprobación.
     * @throws \RuntimeException si la solicitud ya fue procesada.
     */
    public function approve(User $reviewer, ?string $note = null): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \RuntimeException(
                "La solicitud #{$this->id} ya fue procesada (status: {$this->status}). No se puede aprobar de nuevo."
            );
        }

        // SoftDelete del link: deleted_at se rellena, queries normales lo excluyen.
        $this->link()->withTrashed()->first()?->delete();

        $this->update([
            'status'      => self::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }

    /**
     * Rechaza la solicitud: el link permanece activo, se guarda la nota de rechazo.
     *
     * La nota es obligatoria en rechazo para dar feedback al creador.
     * Anti-race: lanza \RuntimeException si status !== pending.
     *
     * @param  User   $reviewer  El usuario que rechaza.
     * @param  string $note      Motivo del rechazo (requerido).
     * @throws \RuntimeException si la solicitud ya fue procesada.
     */
    public function reject(User $reviewer, string $note): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \RuntimeException(
                "La solicitud #{$this->id} ya fue procesada (status: {$this->status}). No se puede rechazar de nuevo."
            );
        }

        $this->update([
            'status'      => self::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }
}
