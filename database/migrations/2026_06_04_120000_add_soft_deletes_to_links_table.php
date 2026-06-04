<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Agrega SoftDeletes a la tabla links.
 *
 * El campo deleted_at permite archivar links aprobados sin borrarlos físicamente.
 * Esto preserva el histórico de link_clicks y mantiene trazabilidad de slugs.
 *
 * LinkRedirectController NO se toca: Link::where('slug', ...)->first() excluye
 * registros con deleted_at != null automáticamente por el trait SoftDeletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->softDeletes(); // agrega deleted_at timestamp nullable
        });
    }

    public function down(): void
    {
        Schema::table('links', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
