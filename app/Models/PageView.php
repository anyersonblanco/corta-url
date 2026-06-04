<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de una visita a /p/{slug}. Inmutable (sin updated_at).
 */
class PageView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'page_id', 'ip_truncated', 'country', 'country_name',
        'device', 'browser', 'os', 'referer_host', 'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
