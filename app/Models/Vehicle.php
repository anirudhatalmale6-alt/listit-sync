<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'dealer_id', 'source_url', 'source_id', 'listit_ad_id',
        'title', 'description', 'price', 'currency',
        'make', 'model', 'year', 'trim', 'body_type',
        'fuel_type', 'transmission', 'engine_size', 'colour',
        'mileage', 'mileage_unit', 'registration', 'vin',
        'co2', 'nct_expiry', 'tax_expiry', 'doors', 'seats',
        'images', 'listit_image_ids', 'has_photos', 'hash',
        'sync_status', 'sync_error', 'last_seen_at',
    ];

    protected $casts = [
        'images' => 'array',
        'listit_image_ids' => 'array',
        'has_photos' => 'boolean',
        'price' => 'decimal:2',
        'nct_expiry' => 'date',
        'tax_expiry' => 'date',
        'last_seen_at' => 'datetime',
    ];

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function computeHash(): string
    {
        return md5(json_encode([
            $this->title, $this->price, $this->make, $this->model,
            $this->year, $this->mileage, $this->images,
        ]));
    }
}
