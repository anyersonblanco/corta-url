<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla `pages` del módulo "Páginas WLink" — equivalente a Bitly Pages / Linktree.
 *
 * Una Page es una mini-landing pública (HTML responsive mobile-first) renderizada
 * en /p/{slug}. Contiene N PageButtons (relación 1:N). Cada botón puede apuntar a
 * un Link interno (acortado) o a una URL externa directa.
 *
 * design_json guarda los tokens visuales:
 *  - template: 'clasico' | 'compacto' | 'avatar'
 *  - bg_color, text_color, button_bg, button_text, button_radius
 *  - font_family
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('title', 255);
            $table->string('description', 500)->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->json('design_json')->nullable(); // template + colores + fonts
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('views_count')->default(0); // denorm visitas a la página
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
