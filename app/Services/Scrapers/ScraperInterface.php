<?php

namespace App\Services\Scrapers;

use App\Models\Dealer;

interface ScraperInterface
{
    /**
     * @return VehicleData[]
     */
    public function scrape(Dealer $dealer): array;

    public function canHandle(Dealer $dealer): bool;
}
