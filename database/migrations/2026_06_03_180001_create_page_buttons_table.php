<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Botones que componen una Page WLink. Cada botón:
 *  - tiene un label (texto visible)
 *  - tiene un icono opcional (heroicon o emoji)
 *  - apunta a UN destino:
 *    a) link_id → un Link interno (acortado, trackea como Link)
 *    b) external_url → URL directa (trackea como engagement de Page)
 *  - tiene un order (drag-to-reorder en el editor)
 *  - puede pausarse sin borrar (is_active)
 *
 * engagements_count = denorm de cuántas veces se clickeó este botón.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_buttons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('label', 200);
            $table->string('icon', 64)->nullable(); // heroicon name o emoji literal
            $table->foreignId('link_id')->nullable()->constrained('links')->nullOnDelete();
            $table->string('external_url', 2048)->nullable();
            $table->unsignedInteger('order')->default(0)->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('engagements_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_buttons');
    }
};
