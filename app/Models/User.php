<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Modelo de usuario de CortarLink.
 *
 * Roles disponibles (Fase 1):
 *  - super_admin : bypass global, ve y hace todo
 *  - supervisor  : creado por super_admin, administra su rama de jefes y cuentas
 *  - jefe        : creado por supervisor, administra sus creadores
 *  - creador     : creado por jefe, crea y edita links en sus cuentas asignadas
 *
 * Jerarquía via parent_id (self-referencial). Profundidad máxima: 3 saltos.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $email
 * @property string      $role
 * @property int|null    $parent_id
 * @property bool        $is_active
 * @property string      $password
 * @property string|null $remember_token
 * @property \Carbon\Carbon|null $email_verified_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'parent_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    // =========================================================================
    // Filament gate
    // =========================================================================

    /**
     * Filament 4 PROD bloquea acceso al panel si User NO implementa este metodo.
     * Restricciones combinadas: solo emails @webtilia.com Y usuario activo.
     * Desactivar un usuario (is_active=false) lo deja inmediatamente fuera del panel
     * sin perder histórico ni cascadear sobre sus subordinados/links.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (!str_ends_with(strtolower($this->email ?? ''), '@webtilia.com')) {
            return false;
        }
        return (bool) $this->is_active;
    }

    // =========================================================================
    // Relaciones
    // =========================================================================

    /**
     * Quién creó a este usuario (supervisor crea jefes, jefe crea creadores, etc.).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * Usuarios que este usuario creó directamente.
     */
    public function children(): HasMany
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /**
     * Cuentas que tiene asignadas este usuario via pivot account_user.
     * Aplica a jefes y creadores. Supervisoras no tienen filas aquí (son dueñas, no asignadas).
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_user')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * Cuentas que este usuario creó / es supervisora de (supervisor_id = $this->id).
     * Solo supervisoras y super_admins tienen filas aquí.
     */
    public function createdAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'supervisor_id');
    }

    // =========================================================================
    // Helpers de rol
    // =========================================================================

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    public function isJefe(): bool
    {
        return $this->role === 'jefe';
    }

    public function isCreador(): bool
    {
        return $this->role === 'creador';
    }

    /**
     * Devuelve los roles que este usuario puede crear directamente.
     * super_admin puede crear cualquier rol.
     * supervisor puede crear solo jefe.
     * jefe puede crear solo creador.
     * creador no puede crear usuarios.
     *
     * @return array<string>
     */
    public function creatableRoles(): array
    {
        return match ($this->role) {
            'super_admin' => ['super_admin', 'supervisor', 'jefe', 'creador'],
            'supervisor'  => ['jefe'],
            'jefe'        => ['creador'],
            default       => [],
        };
    }

    // =========================================================================
    // Jerarquía
    // =========================================================================

    /**
     * Determina si $this supervisa a $user subiendo por parent_id (máx 3 saltos).
     *
     * Algoritmo iterativo con hashset de visitados para protección contra ciclos.
     * "Supervisa" = $user está en la rama descendente de $this, es decir,
     * algún ancestro de $user (siguiendo parent_id hacia arriba) es $this.
     *
     * Saltos máximos: 3 (super_admin → supervisor → jefe → creador).
     * Si la cadena es más larga (configuración inválida), retorna false por seguridad.
     */
    public function supervises(User $user): bool
    {
        if ($this->id === $user->id) {
            return false; // no se supervisa a sí mismo
        }

        $visited  = [];
        $current  = $user;
        $maxSteps = 3;

        for ($step = 0; $step < $maxSteps; $step++) {
            $parentId = $current->parent_id;

            if ($parentId === null) {
                return false;
            }

            // Protección contra ciclos (parent_id apuntando a sí mismo o a descendant)
            if (isset($visited[$parentId])) {
                return false;
            }

            if ($parentId === $this->id) {
                return true;
            }

            $visited[$parentId] = true;

            // Cargar el padre solo si es necesario (evita N+1 si ya fue eager-loaded)
            $parent = $current->relationLoaded('parent')
                ? $current->parent
                : User::find($parentId);

            if ($parent === null) {
                return false;
            }

            $current = $parent;
        }

        return false;
    }

    /**
     * Devuelve las cuentas que este usuario puede asignar a otros.
     *
     *  - super_admin  : todas las cuentas del sistema.
     *  - supervisor   : las cuentas que él creó (createdAccounts).
     *  - jefe         : las cuentas que tiene asignadas (accounts pivot).
     *  - creador      : ninguna (no puede asignar cuentas).
     *
     * @return Collection<int, Account>
     */
    public function assignableAccounts(): Collection
    {
        if ($this->isSuperAdmin()) {
            return Account::all();
        }

        if ($this->isSupervisor()) {
            return $this->createdAccounts()->get();
        }

        if ($this->isJefe()) {
            return $this->accounts()->get();
        }

        return new Collection();
    }

    /**
     * Placeholder Fase 1: indica si este usuario puede aprobar la eliminación
     * de un Link dado. La lógica completa (verificar link.account y rama) se
     * implementa en Fase 4 cuando existen las tablas de cuentas y solicitudes.
     *
     * Por ahora solo evalúa el rol:
     *  - super_admin: siempre puede
     *  - supervisor/jefe: pueden (se refinará en Fase 4 para verificar rama)
     *  - creador: nunca puede (él crea la solicitud, no la aprueba)
     */
    public function canApproveDeletion(Link $link): bool
    {
        return match ($this->role) {
            'super_admin', 'supervisor', 'jefe' => true,
            default => false,
        };
    }
}
