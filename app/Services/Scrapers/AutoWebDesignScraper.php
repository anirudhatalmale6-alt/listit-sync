<?php

namespace App\Services\Scrapers;

use App\Models\Dealer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class AutoWebDesignScraper implements ScraperInterface
{
    public function canHandle(Dealer $dealer): bool
    {
        return $dealer->platform_type === 'autowebdesign';
    }

    public function scrape(Dealer $dealer): array
    {
        $base = rtrim($dealer->website_url, '/');
        $parsedBase = parse_url($base);
        $baseHost = ($parsedBase['scheme'] ?? 'https') . '://' . $parsedBase['host'];
        $vehicles = [];
        $links = [];

        // Paginate through /usedcars listing
        $page = 1;
        while ($page <= 20) {
            $listingUrl = $page === 1
                ? $base . '/usedcars'
                : $base . '/usedcars/page/' . $page;

            $html = $this->fetch($listingUrl);
            if (!$html) break;

            $crawler = new Crawler($html);
            $foundOnPage = 0;

            // AWD sites use /used/make/model/trim/location/region/ID pattern
            $crawler->filter('a[href*="/used/"]')->each(function (Crawler $node) use (&$links, &$foundOnPage, $baseHost) {
                $href = trim($node->attr('href') ?? '');
                if (!$href || str_contains($href, '#')) return;
                if (str_starts_with($href, '/')) {
                    $href = $baseHost . $href;
                }
                if (preg_match('#/used/[^/]+/[^/]+/.+/\d+$#', $href)) {
                    $links[$href] = true;
                    $foundOnPage++;
                }
            });

            // Also try /usedcars/ID pattern (some AWD sites use this)
            $crawler->filter('a[href*="/usedcars/"]')->each(function (Crawler $node) use (&$links, &$foundOnPage, $baseHost) {
                $href = trim($node->attr('href') ?? '');
                if (!$href) return;
                if (str_starts_with($href, '/')) {
                    $href = $baseHost . $href;
                }
                if (preg_match('/\/usedcars\/\d+/', $href)) {
                    $links[$href] = true;
                    $foundOnPage++;
                }
            });

            // Check for next page
            $hasNext = false;
            try {
                $crawler->filter('a[href*="/usedcars/page/"]')->each(function (Crawler $node) use (&$hasNext, $page) {
                    $href = $node->attr('href') ?? '';
                    if (str_contains($href, '/page/' . ($page + 1))) {
                        $hasNext = true;
                    }
                });
            } catch (\Throwable $e) {}

            if (!$hasNext || $foundOnPage === 0) break;
            $page++;
            usleep(300000);
        }

        Log::info("AutoWebDesign: found " . count($links) . " vehicle links for {$dealer->name} across {$page} pages");

        foreach (array_keys($links) as $link) {
            try {
                $vehicleHtml = $this->fetch($link);
                if (!$vehicleHtml) continue;

                $vehicle = $this->parseDetail($vehicleHtml, $link, $dealer);
                if ($vehicle) {
                    $vehicles[] = $vehicle;
                }
            } catch (\Throwable $e) {
                Log::warning("AutoWebDesign: failed to parse {$link}: {$e->getMessage()}");
            }
            usleep(200000);
        }

        return $vehicles;
    }

    protected function parseDetail(string $html, string $url, Dealer $dealer): ?VehicleData
    {
        $crawler = new Crawler($html);
        $title = null;

        $titleSelectors = ['h1.vehicle-title', 'h1.title', '.vehicle-title h1', 'h1'];
        foreach ($titleSelectors as $sel) {
            try {
                $node = $crawler->filter($sel)->first();
                if ($node->count()) {
                    $text = trim($node->text(''));
                    if ($text && strlen($text) > 3 && strlen($text) < 200) {
                        $title = $text;
                        break;
                    }
                }
            } catch (\Throwable $e) {}
        }

        if (!$title) return null;

        $specs = [];

        // Primary: AWD JavaScript variables (most reliable)
        $jsVarMap = [
            'vehiclePrice' => 'price',
            'fuelType' => 'fuel type',
            'transmissionType' => 'transmission',
            'vehicleColour' => 'colour',
            'vehicleYear' => 'year',
            'mileage' => 'mileage',
            'engineSize' => 'engine size',
        ];
        foreach ($jsVarMap as $jsKey => $specKey) {
            if (preg_match("/'" . preg_quote($jsKey) . "'\s*:\s*'([^']+)'/", $html, $m)) {
                $specs[$specKey] = trim($m[1]);
            }
        }

        // Secondary: AWD spec highlights (us-detailsOne-spec-item)
        try {
            $crawler->filter('.us-detailsOne-spec-item, .us-spec-item')->each(function (Crawler $node) use (&$specs) {
                $label = '';
                $value = '';
                try {
                    $labelNode = $node->filter('.us-details-spec-name')->first();
                    if ($labelNode->count()) $label = strtolower(trim($labelNode->text('')));
                } catch (\Throwable $e) {}
                try {
                    $valueNode = $node->filter('strong')->first();
                    if ($valueNode->count()) $value = trim($valueNode->text(''));
                } catch (\Throwable $e) {}

                if ($label && $value) {
                    $specs[$label] = $value;
                }
            });
        } catch (\Throwable $e) {}

        // Fallback: generic spec selectors
        $specSelectors = [
            '.vehicle-specs li', '.spec-item', '.details-list li',
            '.specs-list li', 'ul.details li',
        ];
        foreach ($specSelectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $node) use (&$specs) {
                    $text = trim($node->text(''));
                    if (preg_match('/^(.+?):\s*(.+)$/', $text, $m)) {
                        $key = strtolower(trim($m[1]));
                        if (!isset($specs[$key])) {
                            $specs[$key] = trim($m[2]);
                        }
                    }
                });
            } catch (\Throwable $e) {}
        }

        $images = [];
        $parsedUrl = parse_url($url);
        $baseHost = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

        $imgSelectors = [
            '.us-details-images img', '.js-details-images img',
            '.gallery img', '.vehicle-images img', '.carousel img',
            '.slider img', '.swiper img', '.image-gallery img',
            '.vehicle-gallery img', '.main-image img',
        ];

        foreach ($imgSelectors as $sel) {
            try {
                $crawler->filter($sel)->each(function (Crawler $img) use (&$images, $baseHost) {
                    $src = $img->attr('src') ?? $img->attr('data-src') ?? $img->attr('data-lazy');
                    if (!$src) return;
                    if (str_starts_with($src, '//')) $src = 'https:' . $src;
                    elseif (str_starts_with($src, '/')) $src = $baseHost . $src;
                    if (!str_contains($src, 'logo') && !str_contains($src, 'icon') && !str_contains($src, 'placeholder')) {
                        $images[$src] = true;
                    }
                });
            } catch (\Throwable $e) {}
            if (count($images) > 0) break;
        }

        // Fallback: any large image on the page
        if (empty($images)) {
            try {
                $crawler->filter('img')->each(function (Crawler $img) use (&$images, $baseHost) {
                    $src = $img->attr('src') ?? $img->attr('data-src');
                    if (!$src) return;
                    if (str_starts_with($src, '/')) $src = $baseHost . $src;
                    if (str_contains($src, 'vehicle') || str_contains($src, 'stock') || str_contains($src, 'media/images')) {
                        $images[$src] = true;
                    }
                });
            } catch (\Throwable $e) {}
        }

        // Price: prefer JS variable, fallback to HTML
        $price = null;
        if (isset($specs['price'])) {
            $val = (float) preg_replace('/[^0-9.]/', '', $specs['price']);
            if ($val > 100) $price = $val;
        }

        if (!$price) {
            $priceSelectors = ['.Price', '.us-details-price .Price', '.price', '.vehicle-price', '.now-price'];
            foreach ($priceSelectors as $sel) {
                try {
                    $node = $crawler->filter($sel)->first();
                    if ($node->count()) {
                        $priceText = $node->text('');
                        $cleaned = preg_replace('/[^0-9.]/', '', str_replace(',', '', $priceText));
                        $val = (float) $cleaned;
                        if ($val > 100 && $val < 10000000) {
                            $price = $val;
                            break;
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }

        // Extract source ID from URL (last numeric segment)
        preg_match('/\/(\d+)\s*$/', $url, $idMatch);
        $sourceId = $idMatch[1] ?? md5($url);

        // Parse make/model from URL pattern /used/make/model/...
        $urlMake = null;
        $urlModel = null;
        if (preg_match('#/used/([^/]+)/([^/]+)/#', $url, $urlParts)) {
            $urlMake = ucfirst(str_replace('-', ' ', $urlParts[1]));
            $urlModel = ucfirst(str_replace('-', ' ', $urlParts[2]));
        }

        return new VehicleData(
            sourceId: $sourceId,
            title: $title,
            sourceUrl: $url,
            price: $price,
            currency: $dealer->jurisdiction === 'im' ? 'GBP' : 'EUR',
            make: $specs['make'] ?? $urlMake ?? null,
            model: $specs['model'] ?? $urlModel ?? null,
            year: isset($specs['year']) ? (int) preg_replace('/\D/', '', $specs['year']) : null,
            bodyType: $specs['bodystyle'] ?? $specs['body type'] ?? $specs['body'] ?? $specs['body style'] ?? null,
            fuelType: $specs['fuel type'] ?? $specs['fuel'] ?? null,
            transmission: $specs['transmission'] ?? $specs['gearbox'] ?? null,
            engineSize: $specs['engine size'] ?? $specs['engine'] ?? $specs['cc'] ?? null,
            colour: $specs['colour'] ?? $specs['color'] ?? null,
            mileage: isset($specs['mileage']) ? (int) preg_replace('/[^0-9]/', '', $specs['mileage']) : null,
            registration: $specs['registration'] ?? $specs['reg'] ?? $specs['reg date'] ?? null,
            doors: isset($specs['doors']) ? (int) $specs['doors'] : null,
            images: array_slice(array_keys($images), 0, 20),
        );
    }

    protected function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);
            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
