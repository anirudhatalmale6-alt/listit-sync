<?php

namespace App\Console\Commands;

use App\Models\Dealer;
use App\Services\Scrapers\ScraperManager;
use Illuminate\Console\Command;

class AddDealer extends Command
{
    protected $signature = 'listit:add-dealer
        {url : Dealer website URL}
        {--name= : Dealer name (auto-detected if not given)}
        {--jurisdiction=im : Jurisdiction (ie or im)}
        {--platform= : Platform type override}';

    protected $description = 'Add a new dealer to the sync system';

    public function handle(ScraperManager $manager): int
    {
        $url = rtrim($this->argument('url'), '/');
        $jurisdiction = $this->option('jurisdiction');

        if (!str_starts_with($url, 'http')) {
            $url = 'https://' . $url;
        }

        if (Dealer::where('website_url', $url)->exists()) {
            $this->error("Dealer already exists: {$url}");
            return Command::FAILURE;
        }

        $this->info("Detecting platform for {$url}...");
        $platform = $this->option('platform') ?? $manager->detectPlatform($url);
        $this->info("Platform: {$platform}");

        $name = $this->option('name') ?? $this->detectName($url);

        $dealer = Dealer::create([
            'name' => $name,
            'website_url' => $url,
            'platform_type' => $platform,
            'jurisdiction' => $jurisdiction,
            'tier' => 'free',
            'active' => true,
        ]);

        $this->info("Added dealer #{$dealer->id}: {$dealer->name} ({$platform}, {$jurisdiction})");
        $this->info("Run 'php artisan listit:sync --dealer={$dealer->id}' to test scraping.");

        return Command::SUCCESS;
    }

    protected function detectName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        $host = preg_replace('/^www\./', '', $host);
        $name = explode('.', $host)[0] ?? $host;
        return ucfirst($name);
    }
}
