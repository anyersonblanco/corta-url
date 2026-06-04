<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Sistema de roles jerárquicos CortarLink.
 *
 * Agrega a `users`:
 *  - `role`      string default 'creador'  — rol del usuario en la jerarquía
 *  - `parent_id` FK self-referencial nullable — quién creó a este usuario
 *  - `is_active` boolean default true       — para desactivar sin borrar
 *
 * Profundidad máxima de jerarquía: 3 saltos (super_admin → supervisor → jefe → creador).
 * Roles: super_admin | supervisor | jefe | creador
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // role: string corto (no enum para flexibilidad futura sin ALTER TABLE)
            $table->string('role', 32)
                ->default('creador')
                ->after('email')
                ->index();

            // parent_id: self-referencial nullable; al borrar el padre, queda NULL (huérfano)
            $table->foreignId('parent_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();

            // is_active: desactivación sin borrado de datos
            $table->boolean('is_active')
                ->default(true)
                ->after('parent_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Dropear FK primero, luego columnas
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['role']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['role', 'parent_id', 'is_active']);
        });
    }
};
