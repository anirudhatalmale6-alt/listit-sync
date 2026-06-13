<?php

namespace App\Console\Commands;

use App\Models\Dealer;
use Illuminate\Console\Command;

class ListDealers extends Command
{
    protected $signature = 'listit:dealers
        {--jurisdiction= : Filter by jurisdiction (ie/im)}';

    protected $description = 'List all dealers in the sync system';

    public function handle(): int
    {
        $query = Dealer::query();

        if ($jurisdiction = $this->option('jurisdiction')) {
            $query->where('jurisdiction', $jurisdiction);
        }

        $dealers = $query->withCount('vehicles')->get();

        if ($dealers->isEmpty()) {
            $this->info("No dealers found.");
            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'URL', 'Platform', 'Region', 'Tier', 'Vehicles', 'Last Scraped', 'Status'],
            $dealers->map(fn(Dealer $d) => [
                $d->id,
                $d->name,
                parse_url($d->website_url, PHP_URL_HOST),
                $d->platform_type ?? '-',
                strtoupper($d->jurisdiction),
                $d->tier,
                $d->vehicles_count,
                $d->last_scraped_at?->diffForHumans() ?? 'Never',
                $d->active ? ($d->scrape_failures > 0 ? "Errors({$d->scrape_failures})" : 'OK') : 'Disabled',
            ])->toArray()
        );

        return Command::SUCCESS;
    }
}
