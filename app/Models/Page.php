<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Page WLink — mini-landing pública estilo Linktree / Bitly Pages.
 * Una Page tiene N PageButtons que se renderizan en /p/{slug}.
 *
 * Slugs separados de los de Link (porque la ruta es /p/ vs /l/), pero
 * compartimos el mismo namespace de reserved slugs en ShortLinkService.
 */
class Page extends Model
{
    protected $fillable = [
        'slug', 'title', 'description', 'avatar_url',
        'design_json', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'design_json' => 'array',
        'views_count' => 'integer',
    ];

    /**
     * Tokens visuales default si la Page no tiene design_json customizado.
     * Inspirado en branding Webtilia (azul #0066FF + amarillo #FFD700).
     */
    public const DEFAULT_DESIGN = [
        'template'      => 'clasico',     // 'clasico' | 'compacto' | 'avatar'
        'bg_color'      => '#0066FF',     // gradient desde acá hasta bg_color_to
        'bg_color_to'   => '#0044BB',
        'text_color'    => '#FFFFFF',
        'button_bg'     => '#FFFFFF',
        'button_text'   => '#0044BB',
        'button_radius' => 12,             // px
        'button_style'  => 'solid',       // 'solid' | 'outline' | 'ghost'
        'font_family'   => 'system',      // 'system' | 'serif' | 'mono'
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function buttons(): HasMany
    {
        return $this->hasMany(PageButton::class)->orderBy('order');
    }

    public function views(): HasMany
    {
        return $this->hasMany(PageView::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** URL pública de la Page. Configurable via env como WLink. */
    public function publicUrl(): string
    {
        $base = config('wlink.short_base_url', config('app.url'));
        $prefix = config('wlink.pages_prefix', '/p');
        return rtrim($base, '/') . rtrim($prefix, '/') . '/' . $this->slug;
    }

    /** Devuelve los tokens visuales mezclados con los defaults Webtilia. */
    public function designTokens(): array
    {
        $custom = is_array($this->design_json) ? $this->design_json : [];
        return array_merge(self::DEFAULT_DESIGN, array_filter($custom, fn ($v) => $v !== null && $v !== ''));
    }

    /** Incrementa el counter denorm de visitas (sin race condition). */
    public function incrementViews(): void
    {
        DB::table('pages')->where('id', $this->id)->increment('views_count');
    }

    /** Total engagements (suma de clicks a todos los botones de la Page). */
    public function totalEngagements(): int
    {
        return (int) $this->buttons()->sum('engagements_count');
    }
}
