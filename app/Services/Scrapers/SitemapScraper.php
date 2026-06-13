<?php

namespace App\Services\Scrapers;

use App\Models\Dealer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SitemapScraper implements ScraperInterface
{
    protected UniversalScraper $universalScraper;

    public function __construct()
    {
        $this->universalScraper = new UniversalScraper();
    }

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
                $parsed = @simplexml_load_string($xml);
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

        Log::info("Sitemap: found " . count($vehicleUrls) . " vehicle URLs for {$dealer->name}");

        $vehicles = [];
        foreach ($vehicleUrls as $url) {
            try {
                $html = $this->fetch($url);
                if (!$html) continue;

                $vehicle = $this->universalScraper->parseVehiclePagePublic($html, $url, $dealer);
                if ($vehicle) {
                    $vehicles[] = $vehicle;
                }
            } catch (\Throwable $e) {
                Log::warning("Sitemap: failed to parse {$url}: {$e->getMessage()}");
            }
            usleep(300000);
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
            $base . '/used.xml',
            $base . '/sitemap/vehicles.xml',
            $base . '/sitemap.xml',
            $base . '/sitemap_index.xml',
        ];

        $found = [];
        foreach ($candidates as $url) {
            $content = $this->fetch($url);
            if (!$content) continue;

            if (str_contains($content, '<sitemapindex')) {
                try {
                    $index = @simplexml_load_string($content);
                    if (!$index) continue;
                    foreach ($index->sitemap ?? [] as $entry) {
                        $loc = (string) $entry->loc;
                        if (preg_match('/vehicle|used|car|stock/i', $loc)) {
                            $found[] = $loc;
                        }
                    }
                    if (empty($found)) {
                        foreach ($index->sitemap ?? [] as $entry) {
                            $found[] = (string) $entry->loc;
                        }
                    }
                } catch (\Throwable $e) {}
            } elseif (str_contains($content, '<urlset')) {
                $found[] = $url;
            }

            if (!empty($found)) break;
        }

        return $found;
    }

    protected function isVehicleUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $patterns = ['/used/', '/vehicle/', '/car/', '/stock/', '/inventory/'];
        foreach ($patterns as $p) {
            if (stripos($path, $p) !== false) return true;
        }
        return false;
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml',
                ])
                ->get($url);
            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
