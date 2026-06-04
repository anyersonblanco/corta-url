<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics de visitas a una Page (NO clicks a botones — esos se trackean en
 * link_clicks vía el botón → link redirect, o en una tabla aparte para externos).
 *
 * Una visita = un GET /p/{slug}. Independiente de cuántos botones clickee
 * después el visitante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('ip_truncated', 45)->nullable();
            $table->string('country', 2)->nullable()->index();
            $table->string('country_name', 64)->nullable();
            $table->string('device', 16)->nullable()->index();
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            $table->string('referer_host', 128)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};
