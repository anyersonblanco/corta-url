<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cuenta de cliente Webtilia en CortarLink.
 *
 * Una cuenta agrupa los links de un cliente. Pertenece a una supervisora
 * y puede asignarse a múltiples jefes y creadores via pivot account_user.
 *
 * Flujo de asignación:
 *  supervisora crea la cuenta → la asigna a uno o más de sus jefes
 *  jefe recibe la cuenta → puede asignarla a sus creadores
 *  creador ve la cuenta → debe asociar sus links a ella
 *
 * @property int         $id
 * @property string      $name
 * @property int         $supervisor_id
 * @property bool        $is_active
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'supervisor_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // =========================================================================
    // Relaciones
    // =========================================================================

    /**
     * Supervisora dueña de esta cuenta.
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * Usuarios (jefes y creadores) que tienen esta cuenta asignada.
     * Incluye el pivot: assigned_by + timestamps.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * Links que pertenecen a esta cuenta.
     */
    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Solo cuentas activas.
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    // =========================================================================
    // Helpers de asignación
    // =========================================================================

    /**
     * Asigna esta cuenta a un usuario.
     *
     * Idempotente: si la fila ya existe, no hace nada y retorna false.
     * Si insertó, retorna true.
     *
     * @param  User       $user       El usuario que recibirá la cuenta.
     * @param  User|null  $assignedBy El usuario que hace la asignación (para trazabilidad).
     * @return bool  true = se insertó una fila nueva; false = ya existía.
     */
    public function assignTo(User $user, ?User $assignedBy = null): bool
    {
        // Verificar si ya existe la asignación sin lanzar excepción por PK duplicada
        $exists = $this->users()
            ->where('users.id', $user->id)
            ->exists();

        if ($exists) {
            return false;
        }

        $this->users()->attach($user->id, [
            'assigned_by' => $assignedBy ? $assignedBy->id : null,
        ]);

        return true;
    }

    /**
     * Retira la asignación de esta cuenta para un usuario.
     *
     * Cascada para jefes (Fase 3):
     * Cuando el usuario retirado tiene rol 'jefe', todas las filas pivot de
     * account_user donde:
     *   account_id = $this->id  AND  assigned_by = $user->id
     * también se eliminan, porque esos creadores recibieron la cuenta
     * de la mano de ese jefe.
     *
     * Los links existentes NO se borran ni cambian su account_id.
     * Los creadores afectados simplemente pierden visibilidad del link
     * porque ya no tienen la cuenta asignada.
     *
     * @param  User  $user  El usuario al que se le retira la cuenta.
     */
    public function unassignFrom(User $user): void
    {
        // Cascada: si el user removido es un jefe, borrar también las filas
        // que ÉL asignó a sus creadores para esta misma cuenta.
        if ($user->isJefe()) {
            $removedCount = DB::table('account_user')
                ->where('account_id', $this->id)
                ->where('assigned_by', $user->id)
                ->delete();

            if ($removedCount > 0) {
                Log::info("Cascade unassign: account {$this->id}, jefe {$user->id} → removidos {$removedCount} creadores");
            }
        }

        // Retirar al propio user (jefe o cualquier otro rol)
        $this->users()->detach($user->id);
    }
}
