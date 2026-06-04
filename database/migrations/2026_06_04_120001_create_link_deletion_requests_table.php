<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — Tabla de solicitudes de eliminación de links.
 *
 * Flujo: creador solicita → super_admin/supervisor/jefe aprueba o rechaza.
 * Aprobación = SoftDelete del link. Rechazo = link sigue activo, reason guardado.
 *
 * Nota sobre unique constraint (link_id, status='pending'):
 * No se implementa como UNIQUE column en BD porque MySQL/SQLite no soportan
 * UNIQUE parcial estándar (WHERE status='pending'). El constraint se implementa
 * a nivel de aplicación en el método que crea la solicitud, verificando con
 * exists() antes de insertar. Ver LinkDeletionRequest::createForLink().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_deletion_requests', function (Blueprint $table) {
            $table->id();

            // Link al que se refiere la solicitud.
            // cascadeOnDelete: si un admin borra el link físicamente (raro),
            // la solicitud huérfana se elimina automáticamente.
            $table->foreignId('link_id')
                ->constrained('links')
                ->cascadeOnDelete();

            // Usuario que pidió la eliminación (normalmente un creador).
            // nullOnDelete: si el user se elimina, la solicitud queda sin requester
            // pero no se borra (para trazabilidad histórica).
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Motivo obligatorio — el creador debe justificar la eliminación.
            $table->text('reason');

            // Estado: pending / approved / rejected.
            // Index para queries de badge contador y filtros de listado.
            $table->string('status', 16)->default('pending')->index();

            // Quién revisó la solicitud (aprobó o rechazó).
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Cuándo fue revisada.
            $table->timestamp('reviewed_at')->nullable();

            // Nota del revisor: obligatoria en rechazo, opcional en aprobación.
            $table->text('review_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_deletion_requests');
    }
};
