<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dealer extends Model
{
    protected $fillable = [
        'name', 'website_url', 'platform_type', 'jurisdiction',
        'listit_dealer_id', 'location', 'phone', 'email',
        'tier', 'active', 'last_scraped_at', 'last_synced_at',
        'scrape_failures', 'scrape_error', 'config',
    ];

    protected $casts = [
        'active' => 'boolean',
        'config' => 'array',
        'last_scraped_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }
}
