<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Botón que vive en una Page. Apunta a UN destino:
 *  - link_id (Link interno acortado) → redirect pasa por /l/{slug} + track del Link
 *  - external_url (URL directa)      → redirect directo, track va a engagements_count
 */
class PageButton extends Model
{
    protected $fillable = [
        'page_id', 'label', 'icon', 'link_id', 'external_url',
        'order', 'is_active', 'engagements_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'engagements_count' => 'integer',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /**
     * Resuelve la URL final a la que el botón debe redirigir.
     * - Si tiene link_id válido y el Link es usable → usa el short URL del Link
     *   (así el click se cuenta también en el Link).
     * - Sino → usa external_url directo.
     */
    public function resolveDestination(): ?string
    {
        if ($this->link_id && $this->link) {
            if (!$this->link->isUsable()) {
                return null;
            }
            return $this->link->shortUrl();
        }
        if ($this->external_url) {
            return $this->external_url;
        }
        return null;
    }

    /** Incrementa el contador denorm del botón. */
    public function incrementEngagements(): void
    {
        DB::table('page_buttons')->where('id', $this->id)->increment('engagements_count');
    }

    /**
     * Heroicon o emoji a renderizar como icono del botón.
     * Si el icono es un emoji (1-2 chars unicode), lo retornamos como string raw.
     * Si es nombre Heroicon (ej: 'instagram', 'arrow-right'), retornamos null y
     * el blade se encarga (no incluímos SVG icons en MVP — solo emojis).
     */
    public function displayIcon(): ?string
    {
        return $this->icon ?: null;
    }
}
