<?php

namespace App\Jobs;

use App\Models\Dealer;
use App\Services\SyncEngine;
use App\Services\Scrapers\ScraperManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncDealerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        public Dealer $dealer,
    ) {}

    public function handle(SyncEngine $engine): void
    {
        $engine->syncDealer($this->dealer);
    }
}
