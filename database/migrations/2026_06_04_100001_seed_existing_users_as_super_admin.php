<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 — Migración de datos: todos los usuarios existentes pasan a super_admin.
 *
 * Razonamiento: antes de este feature solo el equipo Webtilia (@webtilia.com)
 * tenía acceso al panel. No existe ningún usuario que deba ser otra cosa que
 * super_admin al momento del rollout. Nuevos usuarios se crean con el rol correcto
 * desde el UserResource.
 *
 * Idempotente: la condición `WHERE role != 'super_admin'` permite correr esta
 * migración múltiples veces sin efecto secundario.
 *
 * down(): deja el role como estaba antes de la migración de datos; como no tenemos
 * snapshot del estado anterior, el rollback restablece a 'creador' (el default de
 * la columna) para indicar que la data migration fue revertida.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->update(['role' => 'super_admin']);
    }

    public function down(): void
    {
        // Al revertir la migración de datos, restablecemos al default 'creador'
        // (el schema down() de la migración anterior eliminaría la columna de todas formas)
        DB::table('users')->update(['role' => 'creador']);
    }
};
