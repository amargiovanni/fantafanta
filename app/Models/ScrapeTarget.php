<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Testata monitorata dallo scraping (Fase 4).
 *
 * Lo stato del circuit breaker non vive qui ma in cache (`App\Scraping\Support\CircuitBreaker`):
 * è effimero per costruzione, si autochiude da solo dopo il cooldown.
 */
#[Fillable(['name', 'url', 'rss_url', 'enabled', 'last_scraped_at', 'last_run_articles_found'])]
class ScrapeTarget extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_scraped_at' => 'datetime',
            'last_run_articles_found' => 'integer',
        ];
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}
