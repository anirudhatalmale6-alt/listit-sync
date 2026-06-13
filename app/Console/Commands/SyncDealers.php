<?php

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Services\SyncEngine;
use App\Services\Scrapers\ScraperManager;
use Illuminate\Console\Command;

class SyncDealers extends Command
{
    protected $signature = 'listit:sync
        {--dealer= : Sync a specific dealer by ID}
        {--jurisdiction= : Filter by jurisdiction (ie/im)}
        {--queue : Dispatch as queued jobs instead of running inline}';

    protected $description = 'Scrape dealer websites and sync vehicles to Listit';

    public function handle(SyncEngine $engine): int
    {
        $dealerId = $this->option('dealer');
        $jurisdiction = $this->option('jurisdiction');
        $useQueue = $this->option('queue');

        if ($dealerId) {
            $dealer = Dealer::findOrFail($dealerId);
            $this->info("Syncing dealer: {$dealer->name}");

            if ($useQueue) {
                \App\Jobs\SyncDealerJob::dispatch($dealer);
                $this->info("Dispatched to queue.");
            } else {
                $stats = $engine->syncDealer($dealer);
                $this->displayStats([$dealer->name => $stats]);
            }
        } else {
            if ($useQueue) {
                \App\Jobs\SyncAllDealersJob::dispatch($jurisdiction);
                $this->info("Dispatched all dealers to queue.");
            } else {
                $this->info("Syncing all " . ($jurisdiction ?? 'ALL') . " dealers...");
                $stats = $engine->syncAll($jurisdiction);
                $this->table(
                    ['Metric', 'Count'],
                    collect($stats)->map(fn($v, $k) => [$k, $v])->values()->toArray()
                );
            }
        }

        return Command::SUCCESS;
    }

    protected function displayStats(array $dealerStats): void
    {
        foreach ($dealerStats as $name => $stats) {
            $this->info("{$name}:");
            $this->table(
                ['Metric', 'Count'],
                collect($stats)->map(fn($v, $k) => [$k, $v])->values()->toArray()
            );
        }
    }
}
