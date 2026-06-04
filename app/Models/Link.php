<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Link acortado del módulo WLink.
 *
 * Lookup principal: por slug (case-sensitive) → debe ser sub-50ms para cumplir
 * el NFR del brief (<200ms redirect end-to-end).
 *
 * Scopes:
 *  - active(): is_active=true AND (expires_at IS NULL OR expires_at > now)
 *  - notExpired(): solo el chequeo de expiración (uso debug)
 */
class Link extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'destination_url', 'title', 'tags',
        'is_active', 'expires_at', 'password_hash', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'clicks_count' => 'integer',
    ];

    /** No exponer password_hash en JSON */
    protected $hidden = ['password_hash'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(LinkClick::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)
            ->where(function ($qq) {
                $qq->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeExpired(Builder $q): Builder
    {
        return $q->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    /**
     * URL corta completa para mostrar al usuario.
     * Hoy vivimos bajo scanner.webs4.wtldevs.com/l/{slug}, en el futuro se migra
     * a go.webtilia.com/{slug} sin prefijo cambiando solo este método + ruta.
     */
    public function shortUrl(): string
    {
        $base = config('wlink.short_base_url', config('app.url'));
        $prefix = config('wlink.short_prefix', '/l');
        return rtrim($base, '/') . rtrim($prefix, '/') . '/' . $this->slug;
    }

    /** URL del QR generado por api.qrserver.com (PNG, sin server-side processing) */
    public function qrUrl(int $size = 300): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?'
            . http_build_query([
                'data' => $this->shortUrl(),
                'size' => "{$size}x{$size}",
                'margin' => 2,
                'ecc' => 'M',
            ]);
    }

    /**
     * Incrementa el contador denormalizado de forma atómica (sin race condition).
     * Usar SIEMPRE este método, no `$link->increment('clicks_count')` directo,
     * porque queremos que sea SQL puro (no requiere cargar el modelo en memoria).
     */
    public function incrementCounter(): void
    {
        DB::table('links')->where('id', $this->id)->increment('clicks_count');
    }

    /** True si está activo Y no expirado. */
    public function isUsable(): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }
}
