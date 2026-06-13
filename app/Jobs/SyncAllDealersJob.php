<?php

namespace App\Jobs;

use App\Models\Dealer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncAllDealersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public function __construct(
        public ?string $jurisdiction = null,
    ) {}

    public function handle(): void
    {
        $query = Dealer::where('active', true)
            ->where('scrape_failures', '<', 10);

        if ($this->jurisdiction) {
            $query->where('jurisdiction', $this->jurisdiction);
        }

        $dealers = $query->orderBy('last_scraped_at')->get();

        foreach ($dealers as $dealer) {
            SyncDealerJob::dispatch($dealer)->onQueue('scraping');
        }
    }
}
