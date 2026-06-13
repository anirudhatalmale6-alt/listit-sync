<?php

namespace App\Services\Scrapers;

use App\Models\Dealer;
use Illuminate\Support\Facades\Log;

class ScraperManager
{
    protected array $scrapers;

    public function __construct()
    {
        $this->scrapers = [
            new AutoWebDesignScraper(),
            new SitemapScraper(),
            new UniversalScraper(), // fallback, always last
        ];
    }

    /**
     * @return VehicleData[]
     */
    public function scrapeDealer(Dealer $dealer): array
    {
        foreach ($this->scrapers as $scraper) {
            if ($scraper->canHandle($dealer)) {
                Log::info("Scraping {$dealer->name} ({$dealer->website_url}) with " . class_basename($scraper));

                try {
                    $vehicles = $scraper->scrape($dealer);
                    Log::info("Found " . count($vehicles) . " vehicles for {$dealer->name}");
                    return $vehicles;
                } catch (\Throwable $e) {
                    Log::error("Scraper error for {$dealer->name}: {$e->getMessage()}");

                    // If specialized scraper fails, try universal fallback
                    if (!($scraper instanceof UniversalScraper)) {
                        Log::info("Falling back to UniversalScraper for {$dealer->name}");
                        try {
                            $universal = new UniversalScraper();
                            return $universal->scrape($dealer);
                        } catch (\Throwable $e2) {
                            Log::error("Universal fallback also failed for {$dealer->name}: {$e2->getMessage()}");
                        }
                    }
                }
            }
        }

        return [];
    }

    public function detectPlatform(string $url): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
                ->get($url);

            if (!$response->successful()) return 'unknown';

            $html = $response->body();
            $headers = $response->headers();

            if (stripos($html, 'autowebdesign') !== false || stripos($html, 'awd_') !== false) {
                return 'autowebdesign';
            }
            if (stripos($html, 'wp-content') !== false || stripos($html, 'wordpress') !== false) {
                return 'wordpress';
            }
            if (stripos($html, 'cogcms') !== false || stripos($html, 'codeweavers') !== false) {
                return 'cogcms';
            }
            if (stripos($html, 'bolt') !== false && stripos($html, 'bolt cms') !== false) {
                return 'bolt';
            }
            if (stripos($html, 'earthstorm') !== false) {
                return 'earthstorm';
            }

            return 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
