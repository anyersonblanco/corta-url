<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 — Tabla de cuentas (clientes Webtilia).
 *
 * Cada cuenta pertenece a una supervisora (supervisor_id).
 * La misma cuenta puede asignarse a múltiples jefes/creadores via pivot account_user.
 *
 * Decisiones técnicas:
 *  - name NO unique: dos supervisoras distintas pueden tener clientes con el mismo nombre.
 *  - supervisor_id: FK cascadeOnDelete — si se borra físicamente la supervisora, se borra la
 *    cuenta. En la práctica los usuarios solo se desactivan (is_active=false), nunca se borran.
 *  - Sin SoftDeletes: Fase 4 decide si los agrega.
 *  - notes: texto libre para que la supervisora deje contexto interno sobre el cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255); // NO unique — dos supervisoras pueden tener nombres iguales
            $table->foreignId('supervisor_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
