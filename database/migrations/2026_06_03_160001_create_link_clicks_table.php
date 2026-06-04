<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de analytics por click del módulo WLink.
 *
 * Por qué tabla separada (no JSON en links):
 *  - Permite agregar/filtrar por país/device/browser sin reprocesar JSON
 *  - Permite charts temporales (clicks por día) con groupBy efficient
 *  - Permite borrar analytics viejos (data retention) sin tocar el link
 *
 * Trade-off: una insert por click. A 1000 clicks/día son 1000 inserts —
 * SQLite los maneja sin sudar. Si crece a 100k+/día migrar a queue async.
 *
 * NO guardamos IP completa por defecto — guardamos /24 truncada (privacy-aware).
 * Si el usuario quiere IP completa, hay que activar config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained('links')->cascadeOnDelete();
            $table->string('ip_truncated', 45)->nullable(); // /24 IPv4 o /48 IPv6 — privacy
            $table->string('country', 2)->nullable()->index(); // ISO 3166-1 alpha-2
            $table->string('country_name', 64)->nullable(); // "Peru", "United States"
            $table->string('device', 16)->nullable()->index(); // "mobile"|"desktop"|"tablet"|"bot"
            $table->string('browser', 32)->nullable(); // "Chrome 124", "Safari 17", "Firefox"
            $table->string('os', 32)->nullable(); // "Windows 11", "macOS", "Android"
            $table->string('referer_host', 128)->nullable(); // hostname del referrer
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_clicks');
    }
};
