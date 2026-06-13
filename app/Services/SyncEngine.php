<?php

namespace App\Services;

use App\Models\Dealer;
use App\Models\SyncLog;
use App\Models\Vehicle;
use App\Services\Listit\ListitApiClient;
use App\Services\Scrapers\ScraperManager;
use App\Services\Scrapers\VehicleData;
use Illuminate\Support\Facades\Log;

class SyncEngine
{
    protected ScraperManager $scraperManager;

    public function __construct(ScraperManager $scraperManager)
    {
        $this->scraperManager = $scraperManager;
    }

    public function syncDealer(Dealer $dealer): array
    {
        $stats = ['scraped' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'errors' => 0];

        Log::info("Starting sync for dealer: {$dealer->name} ({$dealer->website_url})");

        // Step 1: Scrape dealer website
        try {
            $scrapedVehicles = $this->scraperManager->scrapeDealer($dealer);
            $stats['scraped'] = count($scrapedVehicles);

            $dealer->update([
                'last_scraped_at' => now(),
                'scrape_failures' => 0,
                'scrape_error' => null,
            ]);

            SyncLog::create([
                'dealer_id' => $dealer->id,
                'action' => 'scrape',
                'status' => 'success',
                'message' => "Found {$stats['scraped']} vehicles",
            ]);
        } catch (\Throwable $e) {
            $dealer->increment('scrape_failures');
            $dealer->update(['scrape_error' => $e->getMessage()]);

            SyncLog::create([
                'dealer_id' => $dealer->id,
                'action' => 'scrape',
                'status' => 'failure',
                'message' => $e->getMessage(),
            ]);

            Log::error("Scrape failed for {$dealer->name}: {$e->getMessage()}");
            return $stats;
        }

        if (empty($scrapedVehicles)) {
            Log::info("No vehicles found for {$dealer->name}");
            return $stats;
        }

        // Step 2: Upsert vehicles in local DB
        $seenSourceIds = [];
        foreach ($scrapedVehicles as $vehicleData) {
            try {
                $result = $this->upsertVehicle($dealer, $vehicleData);
                $seenSourceIds[] = $vehicleData->sourceId;

                if ($result === 'created') $stats['created']++;
                elseif ($result === 'updated') $stats['updated']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning("Upsert failed: {$e->getMessage()}");
            }
        }

        // Step 3: Mark missing vehicles for removal
        $removed = $dealer->vehicles()
            ->whereNotIn('source_id', $seenSourceIds)
            ->where('sync_status', '!=', 'removed')
            ->where('last_seen_at', '<', now()->subHours(24))
            ->get();

        foreach ($removed as $vehicle) {
            $vehicle->update(['sync_status' => 'removed']);
            $stats['removed']++;
        }

        // Step 4: Push changes to Listit API
        if (config('listit.push_enabled', false)) {
            $this->pushToListit($dealer, $stats);
        }

        $dealer->update(['last_synced_at' => now()]);

        Log::info("Sync complete for {$dealer->name}: " . json_encode($stats));
        return $stats;
    }

    protected function upsertVehicle(Dealer $dealer, VehicleData $data): string
    {
        $existing = Vehicle::where('dealer_id', $dealer->id)
            ->where('source_id', $data->sourceId)
            ->first();

        $attributes = $data->toArray();
        $attributes['dealer_id'] = $dealer->id;
        $attributes['last_seen_at'] = now();

        $newHash = md5(json_encode([
            $data->title, $data->price, $data->make, $data->model,
            $data->year, $data->mileage, $data->images,
        ]));
        $attributes['hash'] = $newHash;

        if ($existing) {
            if ($existing->hash !== $newHash) {
                $existing->update($attributes);
                $existing->update(['sync_status' => 'pending']);
                return 'updated';
            }
            $existing->update(['last_seen_at' => now()]);
            return 'unchanged';
        }

        $attributes['sync_status'] = 'pending';
        Vehicle::create($attributes);
        return 'created';
    }

    protected function pushToListit(Dealer $dealer, array &$stats): void
    {
        $client = new ListitApiClient($dealer->jurisdiction);

        $pending = $dealer->vehicles()
            ->where('sync_status', 'pending')
            ->get();

        foreach ($pending as $vehicle) {
            try {
                if ($vehicle->listit_ad_id) {
                    $success = $client->updateAd($vehicle, $dealer);
                    if ($success) {
                        SyncLog::create([
                            'dealer_id' => $dealer->id,
                            'vehicle_id' => $vehicle->id,
                            'action' => 'update',
                            'status' => 'success',
                            'message' => "Updated ad {$vehicle->listit_ad_id}",
                        ]);
                    }
                } else {
                    $adId = $client->createAd($vehicle, $dealer);
                    if ($adId) {
                        SyncLog::create([
                            'dealer_id' => $dealer->id,
                            'vehicle_id' => $vehicle->id,
                            'action' => 'create',
                            'status' => 'success',
                            'message' => "Created ad {$adId}",
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                SyncLog::create([
                    'dealer_id' => $dealer->id,
                    'vehicle_id' => $vehicle->id,
                    'action' => 'error',
                    'status' => 'failure',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $toRemove = $dealer->vehicles()
            ->where('sync_status', 'removed')
            ->whereNotNull('listit_ad_id')
            ->get();

        foreach ($toRemove as $vehicle) {
            try {
                $client->removeAd($vehicle);
                SyncLog::create([
                    'dealer_id' => $dealer->id,
                    'vehicle_id' => $vehicle->id,
                    'action' => 'remove',
                    'status' => 'success',
                    'message' => "Removed ad {$vehicle->listit_ad_id}",
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to remove ad: {$e->getMessage()}");
            }
        }
    }

    public function syncAll(?string $jurisdiction = null): array
    {
        $query = Dealer::where('active', true);
        if ($jurisdiction) {
            $query->where('jurisdiction', $jurisdiction);
        }

        $dealers = $query->orderBy('last_scraped_at')->get();
        $totalStats = ['dealers' => 0, 'scraped' => 0, 'created' => 0, 'updated' => 0, 'removed' => 0, 'errors' => 0];

        foreach ($dealers as $dealer) {
            if ($dealer->scrape_failures >= 10) {
                Log::info("Skipping {$dealer->name}: too many failures ({$dealer->scrape_failures})");
                continue;
            }

            $stats = $this->syncDealer($dealer);
            $totalStats['dealers']++;
            $totalStats['scraped'] += $stats['scraped'];
            $totalStats['created'] += $stats['created'];
            $totalStats['updated'] += $stats['updated'];
            $totalStats['removed'] += $stats['removed'];
            $totalStats['errors'] += $stats['errors'];

            // Rate limit between dealers
            usleep(500000);
        }

        return $totalStats;
    }
}
