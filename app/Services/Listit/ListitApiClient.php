<?php

namespace App\Services\Listit;

use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ListitApiClient
{
    protected string $baseUrl;
    protected string $jurisdiction;
    protected ?string $token = null;

    public function __construct(string $jurisdiction = 'ie')
    {
        $this->jurisdiction = $jurisdiction;
        $this->baseUrl = $jurisdiction === 'ie'
            ? config('listit.ie.api_url', 'https://api.listit.ie')
            : config('listit.im.api_url', 'https://api.listit.im');
    }

    public function authenticate(): bool
    {
        $email = config("listit.{$this->jurisdiction}.email");
        $password = config("listit.{$this->jurisdiction}.password");

        if (!$email || !$password) {
            Log::error("Listit {$this->jurisdiction}: missing credentials");
            return false;
        }

        try {
            $response = Http::post("{$this->baseUrl}/api/auth/login", [
                'email' => $email,
                'password' => $password,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->token = $data['token'] ?? $data['access_token'] ?? null;

                if ($this->token) {
                    Cache::put("listit_{$this->jurisdiction}_token", $this->token, now()->addHours(12));
                    return true;
                }
            }

            Log::error("Listit {$this->jurisdiction} auth failed: " . $response->status());
            return false;
        } catch (\Throwable $e) {
            Log::error("Listit {$this->jurisdiction} auth error: " . $e->getMessage());
            return false;
        }
    }

    protected function getToken(): ?string
    {
        if ($this->token) return $this->token;

        $cached = Cache::get("listit_{$this->jurisdiction}_token");
        if ($cached) {
            $this->token = $cached;
            return $this->token;
        }

        return $this->authenticate() ? $this->token : null;
    }

    public function createAd(Vehicle $vehicle, Dealer $dealer): ?string
    {
        $token = $this->getToken();
        if (!$token) return null;

        $imageIds = $this->uploadImages($vehicle);

        $payload = [
            'title' => $vehicle->title,
            'description' => $vehicle->description ?? $this->generateDescription($vehicle),
            'price' => $vehicle->price,
            'category_id' => 89, // Cars For Sale
            'parent_category_id' => 62,
            'location' => $dealer->location ?? '',
            'images' => $imageIds,
            'vehicleData' => [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'trim' => $vehicle->trim,
                'body_type' => $vehicle->body_type,
                'fuel_type' => $vehicle->fuel_type,
                'transmission' => $vehicle->transmission,
                'engine_size' => $vehicle->engine_size,
                'colour' => $vehicle->colour,
                'mileage' => $vehicle->mileage,
                'registration_number' => $vehicle->registration,
            ],
            'dealer_id' => $dealer->listit_dealer_id,
        ];

        try {
            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/api/user/ads/create", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $adId = $data['id'] ?? $data['ad_id'] ?? $data['data']['id'] ?? null;

                $vehicle->update([
                    'listit_ad_id' => $adId,
                    'listit_image_ids' => $imageIds,
                    'sync_status' => 'synced',
                    'sync_error' => null,
                ]);

                return $adId;
            }

            Log::error("Listit create ad failed: " . $response->status() . " - " . $response->body());
            $vehicle->update([
                'sync_status' => 'failed',
                'sync_error' => "HTTP {$response->status()}: " . substr($response->body(), 0, 500),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error("Listit create ad error: " . $e->getMessage());
            $vehicle->update(['sync_status' => 'failed', 'sync_error' => $e->getMessage()]);
            return null;
        }
    }

    public function updateAd(Vehicle $vehicle, Dealer $dealer): bool
    {
        $token = $this->getToken();
        if (!$token || !$vehicle->listit_ad_id) return false;

        $imageIds = $vehicle->listit_image_ids ?? $this->uploadImages($vehicle);

        $payload = [
            'title' => $vehicle->title,
            'description' => $vehicle->description ?? $this->generateDescription($vehicle),
            'price' => $vehicle->price,
            'vehicleData' => [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'body_type' => $vehicle->body_type,
                'fuel_type' => $vehicle->fuel_type,
                'transmission' => $vehicle->transmission,
                'engine_size' => $vehicle->engine_size,
                'colour' => $vehicle->colour,
                'mileage' => $vehicle->mileage,
            ],
            'images' => $imageIds,
        ];

        try {
            $response = Http::withToken($token)
                ->put("{$this->baseUrl}/api/user/ads/{$vehicle->listit_ad_id}", $payload);

            if ($response->successful()) {
                $vehicle->update(['sync_status' => 'synced', 'sync_error' => null]);
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error("Listit update ad error: " . $e->getMessage());
            return false;
        }
    }

    public function removeAd(Vehicle $vehicle): bool
    {
        $token = $this->getToken();
        if (!$token || !$vehicle->listit_ad_id) return false;

        try {
            $response = Http::withToken($token)
                ->delete("{$this->baseUrl}/api/user/ads/{$vehicle->listit_ad_id}");

            if ($response->successful()) {
                $vehicle->update(['sync_status' => 'removed', 'listit_ad_id' => null]);
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error("Listit remove ad error: " . $e->getMessage());
            return false;
        }
    }

    protected function uploadImages(Vehicle $vehicle): array
    {
        $token = $this->getToken();
        if (!$token) return [];

        $sourceImages = $vehicle->images ?? [];
        if (empty($sourceImages)) {
            return $this->getPlaceholderImage();
        }

        $uploadedIds = [];
        foreach (array_slice($sourceImages, 0, 10) as $imageUrl) {
            try {
                $imageData = Http::timeout(10)->get($imageUrl);
                if (!$imageData->successful()) continue;

                $tmpFile = tempnam(sys_get_temp_dir(), 'listit_img_');
                file_put_contents($tmpFile, $imageData->body());

                $response = Http::withToken($token)
                    ->attach('image', file_get_contents($tmpFile), 'vehicle.jpg')
                    ->post("{$this->baseUrl}/api/upload/image");

                unlink($tmpFile);

                if ($response->successful()) {
                    $data = $response->json();
                    $id = $data['id'] ?? $data['image_id'] ?? $data['data']['id'] ?? null;
                    if ($id) $uploadedIds[] = $id;
                }
            } catch (\Throwable $e) {
                Log::warning("Image upload failed for {$imageUrl}: " . $e->getMessage());
            }
        }

        return $uploadedIds ?: $this->getPlaceholderImage();
    }

    protected function getPlaceholderImage(): array
    {
        // "Car under cover" placeholder - will be configured per deployment
        $placeholderId = config("listit.{$this->jurisdiction}.placeholder_image_id");
        return $placeholderId ? [$placeholderId] : [];
    }

    protected function generateDescription(Vehicle $vehicle): string
    {
        $parts = array_filter([
            $vehicle->year ? "{$vehicle->year}" : null,
            $vehicle->make,
            $vehicle->model,
            $vehicle->trim,
        ]);

        $desc = implode(' ', $parts);

        if ($vehicle->fuel_type) $desc .= "\nFuel: {$vehicle->fuel_type}";
        if ($vehicle->transmission) $desc .= "\nTransmission: {$vehicle->transmission}";
        if ($vehicle->engine_size) $desc .= "\nEngine: {$vehicle->engine_size}";
        if ($vehicle->mileage) $desc .= "\nMileage: " . number_format($vehicle->mileage) . " {$vehicle->mileage_unit}";
        if ($vehicle->colour) $desc .= "\nColour: {$vehicle->colour}";

        return $desc;
    }

    public function getMakesAndModels(): array
    {
        $token = $this->getToken();
        if (!$token) return [];

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/api/admin/vehicles-make-model");

            return $response->successful() ? $response->json() : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
