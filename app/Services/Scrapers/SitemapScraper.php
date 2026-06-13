<?php

namespace App\Services\Scrapers;

use App\Models\Dealer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class SitemapScraper implements ScraperInterface
{
    public function canHandle(Dealer $dealer): bool
    {
        return $dealer->platform_type === 'earthstorm' || $dealer->platform_type === 'sitemap';
    }

    public function scrape(Dealer $dealer): array
    {
        $base = rtrim($dealer->website_url, '/');
        $sitemapUrls = $this->findSitemaps($base, $dealer);

        $vehicleUrls = [];
        foreach ($sitemapUrls as $sitemapUrl) {
            $xml = $this->fetch($sitemapUrl);
            if (!$xml) continue;

            try {
                $parsed = simplexml_load_string($xml);
                if (!$parsed) continue;

                foreach ($parsed->url ?? [] as $urlEntry) {
                    $loc = (string) $urlEntry->loc;
                    if ($this->isVehicleUrl($loc)) {
                        $vehicleUrls[] = $loc;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to parse sitemap {$sitemapUrl}: {$e->getMessage()}");
            }
        }

        $universalScraper = new UniversalScraper();
        $vehicles = [];

        foreach ($vehicleUrls as $url) {
            try {
                $html = $this->fetch($url);
                if (!$html) continue;

                $crawler = new Crawler($html);
                $vehicle = $this->parseFromCrawler($crawler, $url, $dealer, $universalScraper);
                if ($vehicle) {
                    $vehicles[] = $vehicle;
                }
            } catch (\Throwable $e) {
                Log::warning("Sitemap: failed to parse {$url}: {$e->getMessage()}");
            }
        }

        return $vehicles;
    }

    protected function findSitemaps(string $base, Dealer $dealer): array
    {
        $config = $dealer->config ?? [];
        if (!empty($config['sitemap_url'])) {
            return [$config['sitemap_url']];
        }

        $candidates = [
            $base . '/sitemap.xml',
            $base . '/sitemap/vehicles.xml',
            $base . '/used.xml',
            $base . '/sitemap_index.xml',
        ];

        $found = [];
        foreach ($candidates as $url) {
            $content = $this->fetch($url);
            if ($content && str_contains($content, '<urlset') || str_contains($content, '<sitemapindex')) {
                if (str_contains($content, '<sitemapindex')) {
                    try {
                        $index = simplexml_load_string($content);
                        foreach ($index->sitemap ?? [] as $entry) {
                            $loc = (string) $entry->loc;
                            if (stripos($loc, 'vehicle') !== false || stripos($loc, 'used') !== false || stripos($loc, 'car') !== false || stripos($loc, 'stock') !== false) {
                                $found[] = $loc;
                            }
                        }
                    } catch (\Throwable $e) {}

                    if (empty($found)) {
                        try {
                            foreach ($index->sitemap ?? [] as $entry) {
                                $found[] = (string) $entry->loc;
                            }
                        } catch (\Throwable $e) {}
                    }
                } else {
                    $found[] = $url;
                }
                break;
            }
        }

        return $found;
    }

    protected function isVehicleUrl(string $url): bool
    {
        $patterns = ['vehicle', 'car', 'used', 'stock', 'inventory'];
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        foreach ($patterns as $p) {
            if (stripos($path, $p) !== false) return true;
        }

        return false;
    }

    protected function parseFromCrawler(Crawler $crawler, string $url, Dealer $dealer, UniversalScraper $fallback): ?VehicleData
    {
        // Delegate to universal scraper's page parser via reflection or just re-fetch
        $html = $crawler->html();
        return $fallback->scrape($dealer)[0] ?? null; // Fallback - will be refined
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ListitBot/1.0)'])
                ->get($url);
            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
