<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro individual de un click sobre un Link.
 * Solo timestamp created_at (no updated_at) — los clicks son inmutables.
 */
class LinkClick extends Model
{
    public $timestamps = false; // solo created_at (definido en migration)

    protected $fillable = [
        'link_id', 'ip_truncated', 'country', 'country_name',
        'device', 'browser', 'os', 'referer_host', 'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
