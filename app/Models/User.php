<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Fase 4 — Indica si este usuario puede aprobar/rechazar la solicitud de
     * eliminación de un Link dado.
     *
     * Reglas por rol:
     *  - super_admin : siempre puede (cualquier link del sistema).
     *  - supervisor  : puede si el link tiene account_id y esa cuenta le pertenece
     *                  (createdAccounts), O si el creador del link está en su rama.
     *  - jefe        : puede si el creador del link es uno de sus hijos directos.
     *  - creador     : nunca puede (él crea la solicitud, no la aprueba).
     */
    public function canApproveDeletion(Link $link): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isSupervisor()) {
            // Puede si la cuenta del link le pertenece...
            if ($link->account_id !== null && $this->createdAccounts()->where('id', $link->account_id)->exists()) {
                return true;
            }
            // ...o si el creador del link está en su rama descendente.
            if ($link->created_by !== null && $this->branchUserIds()->contains($link->created_by)) {
                return true;
            }
            return false;
        }

        if ($this->isJefe()) {
            // Solo puede si el creador del link es uno de sus hijos directos.
            if ($link->created_by === null) {
                return false;
            }
            return $this->children()->where('id', $link->created_by)->exists();
        }

        return false; // creador nunca puede
    }

    // =========================================================================
    // Orfandad — detección y reasignación (Fase 5)
    // =========================================================================

    /**
     * Determina si este usuario es "huérfano":
     * está activo, tiene parent_id asignado, pero su parent no existe o está inactivo.
     *
     * super_admin nunca es huérfano (no tiene parent por diseño).
     */
    public function isOrphan(): bool
    {
        // super_admin no tiene parent por diseño — nunca huérfano
        if ($this->isSuperAdmin()) {
            return false;
        }

        // Sin parent_id → no huérfano (usuario raíz no super_admin, ej. supervisor sin asignar)
        if ($this->parent_id === null) {
            return false;
        }

        // Solo los activos pueden ser huérfanos funcionalmente
        if (! $this->is_active) {
            return false;
        }

        // Cargar relación si no está cargada
        $parent = $this->relationLoaded('parent') ? $this->parent : User::find($this->parent_id);

        // Huérfano si el parent no existe o está inactivo
        if ($parent === null) {
            return true;
        }

        return ! $parent->is_active;
    }

    /**
     * Razón de la orfandad para tooltip / log.
     *
     * Retorna:
     *  null                 — no es huérfano
     *  'parent_inactive'    — el parent existe pero está inactivo
     *  'parent_missing'     — parent_id apunta a un ID que no existe en BD
     */
    public function orphanReason(): ?string
    {
        if ($this->isSuperAdmin() || $this->parent_id === null || ! $this->is_active) {
            return null;
        }

        $parent = $this->relationLoaded('parent') ? $this->parent : User::find($this->parent_id);

        if ($parent === null) {
            return 'parent_missing';
        }

        if (! $parent->is_active) {
            return 'parent_inactive';
        }

        return null;
    }

    /**
     * Scope que devuelve usuarios activos con parent inactivo o inexistente.
     *
     * Excluye super_admin (parent_id = null por diseño) via whereNotNull('parent_id').
     * Ejecuta en 1-2 queries SQL usando whereHas/whereDoesntHave.
     */
    public function scopeOrphan(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->whereNotNull('parent_id')
            ->where(function (Builder $inner) {
                // Caso A: parent existe pero está inactivo
                $inner->whereHas('parent', function (Builder $p) {
                    $p->where('is_active', false);
                })
                // Caso B: parent_id apunta a un ID que no existe en la tabla
                ->orWhereDoesntHave('parent');
            });
    }

    /**
     * Reasigna este usuario a un nuevo parent.
     *
     * Validaciones:
     *  - El rol de $newParent debe ser el correcto para el rol de $this:
     *      jefe    → newParent debe ser 'supervisor'
     *      creador → newParent debe ser 'jefe'
     *  - $newParent debe estar activo.
     *
     * @throws \InvalidArgumentException si el rol o el estado no son válidos.
     */
    public function reassignParent(User $newParent): void
    {
        // Validar rol compatible
        $expectedParentRole = match ($this->role) {
            'jefe'    => 'supervisor',
            'creador' => 'jefe',
            default   => null,
        };

        if ($expectedParentRole === null) {
            throw new \InvalidArgumentException(
                "El rol '{$this->role}' no puede tener parent reasignado via reassignParent()."
            );
        }

        if ($newParent->role !== $expectedParentRole) {
            throw new \InvalidArgumentException(
                "Para reasignar un '{$this->role}', el nuevo parent debe tener rol '{$expectedParentRole}'. "
                . "Se recibió rol '{$newParent->role}'."
            );
        }

        if (! $newParent->is_active) {
            throw new \InvalidArgumentException(
                "No se puede reasignar a '{$newParent->name}' porque está inactivo."
            );
        }

        $this->parent_id = $newParent->id;
        $this->save();
    }

    /**
     * Reasigna todos los jefes de $oldParent a $newParent en una sola transacción.
     *
     * Aplica solo a hijos con role='jefe' — no toca creadores ni otras ramas.
     * Loguea la operación con count de afectados.
     *
     * @return int  Cantidad de jefes reasignados.
     * @throws \InvalidArgumentException si $newParent no es supervisor activo.
     */
    public static function reassignBranchTo(User $oldParent, User $newParent): int
    {
        if ($newParent->role !== 'supervisor') {
            throw new \InvalidArgumentException(
                "El destino de reassignBranchTo debe ser rol 'supervisor'. "
                . "Se recibió '{$newParent->role}'."
            );
        }

        if (! $newParent->is_active) {
            throw new \InvalidArgumentException(
                "No se puede reasignar la rama a '{$newParent->name}' porque está inactiva."
            );
        }

        return DB::transaction(function () use ($oldParent, $newParent): int {
            $jefes = User::where('parent_id', $oldParent->id)
                ->where('role', 'jefe')
                ->get();

            $count = 0;
            foreach ($jefes as $jefe) {
                $jefe->parent_id = $newParent->id;
                $jefe->save();
                $count++;
            }

            if ($count > 0) {
                Log::info("Reassign branch: supervisor {$oldParent->id} → {$newParent->id}, jefes movidos: {$count}");
            }

            return $count;
        });
    }

    // =========================================================================
    // Rama jerárquica (Fase 3)
    // =========================================================================

    /**
     * Devuelve los IDs de TODOS los usuarios en la rama descendente del user actual,
     * incluyendo al user mismo.
     *
     * Profundidad máxima soportada: 3 niveles descendentes (equivale a
     * supervisor → jefe → creador desde el supervisor).
     *
     * Algoritmo iterativo (queue BFS) con hashset de visitados para anti-ciclos.
     * Ejecuta en máximo 3 queries IN (una por nivel), nunca N+1.
     *
     * Reglas por rol:
     *  - super_admin  : devuelve todos los user IDs del sistema.
     *  - supervisor   : él mismo + sus jefes (nivel 1) + creadores de sus jefes (nivel 2).
     *  - jefe         : él mismo + sus creadores directos (nivel 1).
     *  - creador      : solo él mismo.
     *
     * @return SupportCollection<int, int>  Collection de integers (IDs).
     */
    public function branchUserIds(): SupportCollection
    {
        // super_admin ve todo el sistema
        if ($this->isSuperAdmin()) {
            return User::pluck('id');
        }

        $visited = [];   // hashset para anti-ciclos
        $result  = [];   // IDs acumulados

        // Incluir al propio user
        $visited[$this->id] = true;
        $result[]           = $this->id;

        // Queue de IDs cuyo nivel descendente hay que cargar
        $currentLevelIds = [$this->id];
        $maxDepth        = 3; // profundidad máxima de descenso

        for ($depth = 0; $depth < $maxDepth && !empty($currentLevelIds); $depth++) {
            // Una sola query por nivel — evita N+1
            $children = User::whereIn('parent_id', $currentLevelIds)
                ->pluck('id')
                ->all();

            $nextLevelIds = [];

            foreach ($children as $childId) {
                // Anti-ciclo: si ya está visitado, saltar
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $result[]          = $childId;
                $nextLevelIds[]    = $childId;
            }

            $currentLevelIds = $nextLevelIds;
        }

        return collect($result);
    }
}
