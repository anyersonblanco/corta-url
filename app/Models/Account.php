<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Nota Fase 3: cuando se retire la cuenta de un jefe, también habrá que
     * borrar las asignaciones de sus creadores para esta cuenta. Por ahora
     * esta función solo borra la fila del usuario dado (Fase 3 maneja cascada).
     *
     * @param  User  $user  El usuario al que se le retira la cuenta.
     */
    public function unassignFrom(User $user): void
    {
        $this->users()->detach($user->id);
    }
}
