<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Agrega account_id a la tabla links.
 *
 * Pre-feature: todos los links existentes quedan con account_id = NULL ("Sin asignar").
 * Fase 3 agrega la validación en UI que obliga a creadores a elegir cuenta.
 *
 * FK nullOnDelete: si la cuenta es eliminada (solo posible con borrado físico,
 * que no ocurre en la práctica), el link queda "Sin asignar" en vez de borrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('created_by')
                ->constrained('accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
