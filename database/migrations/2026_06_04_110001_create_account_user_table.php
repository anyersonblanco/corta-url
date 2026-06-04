<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Tabla pivot account_user.
 *
 * Registra qué usuarios (jefes y creadores) tienen asignada qué cuenta.
 * También registra quién hizo la asignación (assigned_by).
 *
 * Cadena de asignación:
 *  supervisor asigna account → jefe:
 *    (account_id=C, user_id=JEFE, assigned_by=SUPERVISORA_ID)
 *  jefe asigna account → creador (solo cuentas que él ya tiene asignadas):
 *    (account_id=C, user_id=CREADOR, assigned_by=JEFE_ID)
 *
 * Decisiones técnicas:
 *  - PK compuesta (account_id, user_id): garantiza unicidad sin modelo Pivot.
 *  - assigned_by nullable + nullOnDelete: si el asignador es eliminado, la
 *    asignación se conserva pero pierde trazabilidad del origen. Aceptable.
 *  - Solo created_at (sin updated_at): una asignación no se edita, se borra y se recrea.
 *    updated_at se agrega nullable para que Eloquent withTimestamps() no falle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_user', function (Blueprint $table) {
            // PK compuesta
            $table->foreignId('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->primary(['account_id', 'user_id']);

            // Trazabilidad de quién hizo la asignación
            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Solo created_at — las asignaciones no se editan
            $table->timestamp('created_at')->nullable()->useCurrent();
            // updated_at nullable para que Eloquent withTimestamps() no falle (nunca se usa)
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_user');
    }
};
