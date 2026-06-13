<?php

namespace App\Services\Scrapers;

use App\Models\Dealer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class UniversalScraper implements ScraperInterface
{
    public function canHandle(Dealer $dealer): bool
    {
        return true;
    }

    public function scrape(Dealer $dealer): array
    {
        $stockUrl = $this->findStockPageUrl($dealer);
        $html = $this->fetchPage($stockUrl);
        if (!$html) {
            return [];
        }

        $crawler = new Crawler($html);
        $vehicleLinks = $this->extractVehicleLinks($crawler, $dealer->website_url);

        if (empty($vehicleLinks)) {
            Log::warning("No vehicle links found on {$stockUrl}");
            return [];
        }

        $vehicles = [];
        foreach ($vehicleLinks as $link) {
            try {
                $vehicleHtml = $this->fetchPage($link);
                if (!$vehicleHtml) continue;

                $vehicle = $this->parseVehiclePage($vehicleHtml, $link, $dealer);
                if ($vehicle) {
                    $vehicles[] = $vehicle;
                }
            } catch (\Throwable $e) {
                Log::warning("Failed to parse vehicle at {$link}: {$e->getMessage()}");
            }
        }

        return $vehicles;
    }

    protected function findStockPageUrl(Dealer $dealer): string
    {
        $config = $dealer->config ?? [];
        if (!empty($config['stock_url'])) {
            return $config['stock_url'];
        }

        $base = rtrim($dealer->website_url, '/');
        $stockPaths = [
            '/stock', '/used-cars', '/usedcars', '/cars-for-sale',
            '/vehicles', '/used-vehicles', '/inventory', '/our-cars',
            '/car-sales', '/cars', '/our-stock', '/search',
            '/showroom', '/for-sale', '/available',
        ];

        // Check each candidate path, verify it has vehicle-like links
        foreach ($stockPaths as $path) {
            $url = $base . $path;
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
                    ->get($url);
                if ($response->successful() && strlen($response->body()) > 1000) {
                    $body = $response->body();
                    if (preg_match('/href="[^"]*(?:vehicle|car|used|stock|our-cars|listing)[^"]*"/i', $body)) {
                        return $url;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Fallback: return first page that loads, even without vehicle links
        foreach ($stockPaths as $path) {
            $url = $base . $path;
            try {
                $response = Http::timeout(10)->get($url);
                if ($response->successful() && strlen($response->body()) > 1000) {
                    return $url;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $base;
    }

    protected function fetchPage(string $url): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch {$url}: {$e->getMessage()}");
            return null;
        }
    }

    protected function extractVehicleLinks(Crawler $crawler, string $baseUrl): array
    {
        $links = [];
        $base = rtrim($baseUrl, '/');

        $vehiclePatterns = [
            'a[href*="vehicle"]', 'a[href*="car"]', 'a[href*="stock"]',
            'a[href*="used"]', 'a[href*="inventory"]',
            'a[href*="our-cars"]', 'a[href*="our-stock"]',
            'a[href*="usedcars"]', 'a[href*="used-cars"]',
            'a[href*="cars-for-sale"]', 'a[href*="car-for-sale"]',
            'a[href*="used-vehicles"]',
            '.vehicle-card a', '.car-card a', '.stock-item a',
            '.vehicle-listing a', '.car-listing a',
            '[data-vehicle] a', '[data-car] a',
            '.results a', '.listing a',
        ];

        foreach ($vehiclePatterns as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$links, $base) {
                    $href = $node->attr('href');
                    if (!$href) return;

                    if (str_starts_with($href, '/')) {
                        $parsed = parse_url($base);
                        $href = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . $href;
                    } elseif (!str_starts_with($href, 'http')) {
                        $href = $base . '/' . $href;
                    }

                    if ($this->isVehicleDetailLink($href)) {
                        $links[$href] = true;
                    }
                });
            } catch (\Throwable $e) {
                continue;
            }
        }

        return array_keys($links);
    }

    protected function isVehicleDetailLink(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $skipPatterns = ['/category', '/car-manufacturer/', '/car-model/', '/make/', '/model/', '/filter', '/search', '/page/', '#', 'javascript:', '/feed/', '/wp-json/'];
        foreach ($skipPatterns as $skip) {
            if (stripos($url, $skip) !== false) return false;
        }

        // Check for query-string vehicle detail pages (/vehicle?id=xxx)
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        if (stripos($path, '/vehicle') !== false && str_contains($query, 'id=')) {
            return true;
        }

        $detailPatterns = ['/vehicle/', '/car/', '/car-for-sale', '/stock/', '/used-', '/detail', '/listing/', '/our-cars/', '/our-stock/', '/usedcars/', '/cars-for-sale/'];
        foreach ($detailPatterns as $pattern) {
            if (stripos($path, $pattern) !== false) {
                $segments = array_filter(explode('/', trim($path, '/')));
                if (count($segments) >= 2) return true;
            }
        }

        $segments = array_filter(explode('/', trim($path, '/')));
        return count($segments) >= 2 && preg_match('/\d/', $path);
    }

    public function parseVehiclePagePublic(string $html, string $url, Dealer $dealer): ?VehicleData
    {
        return $this->parseVehiclePage($html, $url, $dealer);
    }

    protected function parseVehiclePage(string $html, string $url, Dealer $dealer): ?VehicleData
    {
        $crawler = new Crawler($html);

        $title = $this->extractTitle($crawler);
        if (!$title) {
            $title = $this->titleFromUrl($url);
        }
        if (!$title) return null;

        $parsed = $this->parseTitleForMakeModel($title);
        $images = $this->extractImages($crawler, $url);
        $specs = $this->extractSpecs($crawler, $html);
        $price = isset($specs['price']) ? (float) $specs['price'] : $this->extractPrice($crawler, $html);
        $description = $this->extractDescription($crawler);

        // Extract source ID from URL - try query param first, then hash
        $queryStr = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($queryStr, $queryParams);
        $sourceId = $queryParams['id'] ?? md5($url);

        return new VehicleData(
            sourceId: $sourceId,
            title: $title,
            sourceUrl: $url,
            description: $description,
            price: $price,
            currency: $dealer->jurisdiction === 'im' ? 'GBP' : 'EUR',
            make: $specs['make'] ?? $parsed['make'] ?? null,
            model: $specs['model'] ?? $parsed['model'] ?? null,
            year: $specs['year'] ?? $parsed['year'] ?? null,
            bodyType: $specs['body_type'] ?? null,
            fuelType: $specs['fuel_type'] ?? null,
            transmission: $specs['transmission'] ?? null,
            engineSize: $specs['engine_size'] ?? null,
            colour: $specs['colour'] ?? null,
            mileage: $specs['mileage'] ?? null,
            mileageUnit: $dealer->jurisdiction === 'im' ? 'miles' : 'km',
            registration: $specs['registration'] ?? null,
            doors: $specs['doors'] ?? null,
            seats: $specs['seats'] ?? null,
            images: $images,
        );
    }

    protected function extractTitle(Crawler $crawler): ?string
    {
        $selectors = [
            'h1.vehicle-title', 'h1.car-title', '.vehicle-detail h1',
            '.car-detail h1', '.listing-title h1', 'h1', 'h2', 'h3',
        ];

        $genericPatterns = ['car sales', 'vehicle sales', 'used cars', 'showroom', 'home page', 'welcome', 'value your car', 'sell your car', 'trade in'];

        foreach ($selectors as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
                if ($node->count()) {
                    $text = trim($node->text(''));
                    if ($text && strlen($text) > 3 && strlen($text) < 200) {
                        $lower = strtolower($text);
                        $isGeneric = false;
                        foreach ($genericPatterns as $gp) {
                            if (str_contains($lower, $gp)) { $isGeneric = true; break; }
                        }
                        if (!$isGeneric) return $text;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Try <title> tag as last resort
        try {
            $titleTag = $crawler->filter('title')->first();
            if ($titleTag->count()) {
                $text = trim($titleTag->text(''));
                // Extract vehicle name from "Used Porsche 718 Spyder - Van Mossel" format
                $text = preg_replace('/\s*[-|]\s*.*$/', '', $text);
                $text = preg_replace('/^Used\s+/i', '', $text);
                if ($text && strlen($text) > 5 && strlen($text) < 150) {
                    $lower = strtolower($text);
                    $isGeneric = false;
                    foreach ($genericPatterns as $gp) {
                        if (str_contains($lower, $gp)) { $isGeneric = true; break; }
                    }
                    if (!$isGeneric) return $text;
                }
            }
        } catch (\Throwable $e) {}

        return null;
    }

    protected function titleFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $segments = array_filter(explode('/', trim($path, '/')));
        $slug = end($segments);
        if (!$slug) return null;

        $title = str_replace(['-', '_'], ' ', $slug);
        $title = ucwords($title);

        $skipWords = ['page', 'index', 'search', 'filter', 'category'];
        if (in_array(strtolower($title), $skipWords)) return null;

        return strlen($title) > 5 ? $title : null;
    }

    protected function extractPrice(Crawler $crawler, string $html): ?float
    {
        $selectors = [
            '.price', '.vehicle-price', '.car-price', '.listing-price',
            '[data-price]', '.cost', '.amount',
        ];

        foreach ($selectors as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
                if ($node->count()) {
                    $text = $node->text('');
                    $price = $this->parsePrice($text);
                    if ($price) return $price;

                    $dataPrice = $node->attr('data-price');
                    if ($dataPrice) {
                        $price = $this->parsePrice($dataPrice);
                        if ($price) return $price;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        if (preg_match('/[€£]\s?[\d,]+(?:\.\d{2})?/', $html, $m)) {
            return $this->parsePrice($m[0]);
        }

        return null;
    }

    protected function parsePrice(string $text): ?float
    {
        $cleaned = preg_replace('/[^0-9.,]/', '', $text);
        $cleaned = str_replace(',', '', $cleaned);
        $value = (float) $cleaned;
        return ($value > 100 && $value < 10000000) ? $value : null;
    }

    protected function extractImages(Crawler $crawler, string $pageUrl): array
    {
        $images = [];
        $selectors = [
            '.gallery img', '.vehicle-images img', '.car-images img',
            '.slideshow img', '.carousel img', '.swiper img',
            '.vehicle-gallery img', '.car-gallery img',
            '[data-gallery] img', '.image-gallery img',
            '.detail-images img', '.photos img',
        ];

        $parsedBase = parse_url($pageUrl);
        $baseHost = ($parsedBase['scheme'] ?? 'https') . '://' . ($parsedBase['host'] ?? '');

        foreach ($selectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$images, $baseHost) {
                    $src = $node->attr('src') ?? $node->attr('data-src') ?? $node->attr('data-lazy-src');
                    if (!$src) return;

                    if (str_starts_with($src, '//')) {
                        $src = 'https:' . $src;
                    } elseif (str_starts_with($src, '/')) {
                        $src = $baseHost . $src;
                    }

                    if ($this->isValidCarImage($src)) {
                        $images[$src] = true;
                    }
                });
            } catch (\Throwable $e) {
                continue;
            }

            if (count($images) > 0) break;
        }

        if (empty($images)) {
            try {
                $crawler->filter('img')->each(function (Crawler $node) use (&$images, $baseHost) {
                    $src = $node->attr('src') ?? $node->attr('data-src');
                    if (!$src) return;

                    if (str_starts_with($src, '/')) {
                        $src = $baseHost . $src;
                    }

                    if ($this->isValidCarImage($src)) {
                        $images[$src] = true;
                    }
                });
            } catch (\Throwable $e) {}
        }

        return array_slice(array_keys($images), 0, 20);
    }

    protected function isValidCarImage(string $src): bool
    {
        $skipPatterns = ['logo', 'icon', 'favicon', 'banner', 'placeholder', 'avatar', 'sprite', 'widget', 'button', 'badge', 'social', 'payment'];
        foreach ($skipPatterns as $pattern) {
            if (stripos($src, $pattern) !== false) return false;
        }

        if (preg_match('/\.(jpg|jpeg|png|webp)/i', $src)) {
            return true;
        }

        if (stripos($src, 'image') !== false || stripos($src, 'photo') !== false || stripos($src, 'vehicle') !== false) {
            return true;
        }

        return false;
    }

    protected function extractDescription(Crawler $crawler): ?string
    {
        $selectors = [
            '.vehicle-description', '.car-description', '.description',
            '.listing-description', '.details-text', '.vehicle-info',
        ];

        foreach ($selectors as $selector) {
            try {
                $node = $crawler->filter($selector)->first();
                if ($node->count()) {
                    $text = trim($node->text(''));
                    if ($text && strlen($text) > 20) {
                        return Str::limit($text, 2000);
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    protected function extractSpecs(Crawler $crawler, string $html = ''): array
    {
        $specs = [];

        // Try JavaScript embedded data (Earthstorm/modern sites)
        if ($html) {
            $jsMap = [
                'cashPrice' => 'price',
                'mileage' => 'mileage',
            ];
            foreach ($jsMap as $jsKey => $specKey) {
                if (preg_match('/' . preg_quote($jsKey) . '\s*:\s*(\d+)/', $html, $m)) {
                    $specs[$specKey] = $m[1];
                }
            }
        }

        $specLabels = [
            'make' => ['make', 'manufacturer', 'brand'],
            'model' => ['model'],
            'year' => ['year', 'reg year', 'registration year', 'first registered'],
            'body_type' => ['body', 'body type', 'body style', 'type'],
            'fuel_type' => ['fuel', 'fuel type', 'fuel type:'],
            'transmission' => ['transmission', 'gearbox', 'trans'],
            'engine_size' => ['engine', 'engine size', 'cc', 'capacity', 'engine capacity'],
            'colour' => ['colour', 'color', 'ext colour', 'exterior colour', 'exterior color'],
            'mileage' => ['mileage', 'miles', 'km', 'odometer', 'odo'],
            'registration' => ['reg', 'registration', 'reg no', 'number plate'],
            'doors' => ['doors', 'door'],
            'seats' => ['seats', 'seating'],
        ];

        // Try table rows (th/td, dt/dd)
        $this->extractFromTableRows($crawler, $specLabels, $specs);

        // Try spec list items
        $this->extractFromSpecLists($crawler, $specLabels, $specs);

        // Try structured data (JSON-LD)
        $this->extractFromJsonLd($crawler, $specs);

        // Parse numeric values
        if (isset($specs['year'])) {
            $specs['year'] = (int) preg_replace('/\D/', '', $specs['year']);
            if ($specs['year'] < 1900 || $specs['year'] > 2030) unset($specs['year']);
        }
        if (isset($specs['mileage'])) {
            $specs['mileage'] = (int) preg_replace('/[^0-9]/', '', $specs['mileage']);
        }
        if (isset($specs['doors'])) {
            $specs['doors'] = (int) preg_replace('/\D/', '', $specs['doors']);
        }
        if (isset($specs['seats'])) {
            $specs['seats'] = (int) preg_replace('/\D/', '', $specs['seats']);
        }

        return $specs;
    }

    protected function extractFromTableRows(Crawler $crawler, array $specLabels, array &$specs): void
    {
        $selectors = ['table tr', 'dl', '.specs li', '.spec-list li', '.details-list li', '.features li', '.row.spec', '.spec-row', '.spec-item'];

        foreach ($selectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $row) use ($specLabels, &$specs) {
                    $cells = [];
                    $row->filter('th, td, dt, dd, .label, .value, strong, span')->each(function (Crawler $cell) use (&$cells) {
                        $cells[] = trim($cell->text(''));
                    });

                    if (count($cells) >= 2) {
                        $label = strtolower($cells[0]);
                        $value = $cells[1];
                        $this->matchSpec($label, $value, $specLabels, $specs);
                    } elseif (count($cells) === 1) {
                        $text = $cells[0];
                        if (preg_match('/^(.+?):\s*(.+)$/', $text, $m)) {
                            $this->matchSpec(strtolower(trim($m[1])), trim($m[2]), $specLabels, $specs);
                        }
                    }
                });
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    protected function extractFromSpecLists(Crawler $crawler, array $specLabels, array &$specs): void
    {
        try {
            $text = $crawler->text('');
            foreach ($specLabels as $key => $labels) {
                if (isset($specs[$key])) continue;
                foreach ($labels as $label) {
                    if (preg_match('/' . preg_quote($label, '/') . '\s*[:\-]\s*(.+?)(?:\n|<|$)/i', $text, $m)) {
                        $value = trim($m[1]);
                        if ($value && strlen($value) < 100) {
                            $specs[$key] = $value;
                            break;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    protected function extractFromJsonLd(Crawler $crawler, array &$specs): void
    {
        try {
            $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$specs) {
                $json = json_decode($node->text(''), true);
                if (!$json) return;

                if (($json['@type'] ?? '') === 'Car' || ($json['@type'] ?? '') === 'Vehicle') {
                    $specs['make'] = $specs['make'] ?? $json['brand']['name'] ?? $json['brand'] ?? null;
                    $specs['model'] = $specs['model'] ?? $json['model'] ?? null;
                    $specs['year'] = $specs['year'] ?? $json['vehicleModelDate'] ?? $json['productionDate'] ?? null;
                    $specs['fuel_type'] = $specs['fuel_type'] ?? $json['fuelType'] ?? null;
                    $specs['mileage'] = $specs['mileage'] ?? $json['mileageFromOdometer']['value'] ?? null;
                    $specs['colour'] = $specs['colour'] ?? $json['color'] ?? null;
                    $specs['transmission'] = $specs['transmission'] ?? $json['vehicleTransmission'] ?? null;
                    $specs['engine_size'] = $specs['engine_size'] ?? $json['vehicleEngine']['engineDisplacement'] ?? null;
                }
            });
        } catch (\Throwable $e) {}
    }

    protected function matchSpec(string $label, string $value, array $specLabels, array &$specs): void
    {
        $label = preg_replace('/[^a-z0-9\s]/', '', $label);
        foreach ($specLabels as $key => $labels) {
            if (isset($specs[$key])) continue;
            foreach ($labels as $matchLabel) {
                if (str_contains($label, $matchLabel) && $value && strlen($value) < 100) {
                    $specs[$key] = $value;
                    return;
                }
            }
        }
    }

    protected function parseTitleForMakeModel(string $title): array
    {
        $makes = [
            'Toyota', 'Ford', 'BMW', 'Mercedes', 'Audi', 'Volkswagen', 'VW',
            'Honda', 'Nissan', 'Hyundai', 'Kia', 'Peugeot', 'Renault', 'Citroen',
            'Vauxhall', 'Opel', 'Skoda', 'Seat', 'Fiat', 'Mazda', 'Suzuki',
            'Volvo', 'Land Rover', 'Jaguar', 'Mini', 'Lexus', 'Subaru', 'Mitsubishi',
            'Dacia', 'MG', 'Cupra', 'Tesla', 'Porsche', 'Jeep', 'Range Rover',
            'Chevrolet', 'Dodge', 'Chrysler', 'Alfa Romeo',
        ];

        $result = ['make' => null, 'model' => null, 'year' => null];

        if (preg_match('/\b(19|20)\d{2}\b/', $title, $yearMatch)) {
            $result['year'] = (int) $yearMatch[0];
        }

        foreach ($makes as $make) {
            if (stripos($title, $make) !== false) {
                $result['make'] = $make;
                $pos = stripos($title, $make) + strlen($make);
                $rest = trim(substr($title, $pos));
                $rest = preg_replace('/^\s*[-–]\s*/', '', $rest);
                $words = explode(' ', $rest);
                $modelWords = [];
                foreach ($words as $w) {
                    $w = trim($w);
                    if (!$w || preg_match('/^(19|20)\d{2}$/', $w)) break;
                    if (in_array(strtolower($w), ['for', 'sale', 'price', '-', '–', '|'])) break;
                    $modelWords[] = $w;
                    if (count($modelWords) >= 3) break;
                }
                if ($modelWords) {
                    $result['model'] = implode(' ', $modelWords);
                }
                break;
            }
        }

        return $result;
    }
}
