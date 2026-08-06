<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Testata monitorata dallo scraping (attivo in Fase 4).
 */
#[Fillable(['name', 'url', 'rss_url', 'enabled', 'last_scraped_at'])]
class ScrapeTarget extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_scraped_at' => 'datetime',
        ];
    }
}
