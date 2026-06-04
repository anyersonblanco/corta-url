<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla principal del módulo WLink (acortador de URLs Webtilia).
 *
 * Diseño:
 *  - slug es la PK natural (case-sensitive). Index unique con prefijo corto
 *    para soportar lookups O(log n) sobre 100k+ enlaces.
 *  - clicks_count es un denormalized counter incrementado en cada redirect
 *    via Link::incrementCounter() — evita full table scan a link_clicks.
 *  - password_hash nullable (feature de v1.1) — si está seteado, el redirect
 *    pasa primero por una pantalla de password.
 *  - destination_url es la URL larga real. Soporta hasta 2048 chars (límite
 *    práctico de URL en browsers modernos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->text('destination_url'); // hasta ~64KB; el front limita a 2048
            $table->string('title', 255)->nullable(); // descripción humana
            $table->string('tags', 255)->nullable(); // comma-separated: "campania-mayo,instagram"
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('password_hash')->nullable(); // v1.1 — null = público
            $table->unsignedBigInteger('clicks_count')->default(0); // denormalized counter
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->index('created_at'); // para sort por fecha en admin
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
