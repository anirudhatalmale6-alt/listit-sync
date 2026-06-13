<?php

namespace App\Services\Scrapers;

class VehicleData
{
    public function __construct(
        public string $sourceId,
        public string $title,
        public ?string $sourceUrl = null,
        public ?string $description = null,
        public ?float $price = null,
        public string $currency = 'EUR',
        public ?string $make = null,
        public ?string $model = null,
        public ?int $year = null,
        public ?string $trim = null,
        public ?string $bodyType = null,
        public ?string $fuelType = null,
        public ?string $transmission = null,
        public ?string $engineSize = null,
        public ?string $colour = null,
        public ?int $mileage = null,
        public string $mileageUnit = 'km',
        public ?string $registration = null,
        public ?string $vin = null,
        public ?string $co2 = null,
        public ?string $nctExpiry = null,
        public ?string $taxExpiry = null,
        public ?int $doors = null,
        public ?int $seats = null,
        public array $images = [],
    ) {}

    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source_url' => $this->sourceUrl,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'trim' => $this->trim,
            'body_type' => $this->bodyType,
            'fuel_type' => $this->fuelType,
            'transmission' => $this->transmission,
            'engine_size' => $this->engineSize,
            'colour' => $this->colour,
            'mileage' => $this->mileage,
            'mileage_unit' => $this->mileageUnit,
            'registration' => $this->registration,
            'vin' => $this->vin,
            'co2' => $this->co2,
            'nct_expiry' => $this->nctExpiry,
            'tax_expiry' => $this->taxExpiry,
            'doors' => $this->doors,
            'seats' => $this->seats,
            'images' => $this->images,
            'has_photos' => !empty($this->images),
        ];
    }
}
