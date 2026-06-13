<?php

namespace Database\Seeders;

use App\Models\Dealer;
use Illuminate\Database\Seeder;

class IsleOfManDealerSeeder extends Seeder
{
    public function run(): void
    {
        $dealers = [
            ['name' => 'BCC Cars', 'url' => 'https://www.bcccars.im', 'platform' => 'autowebdesign'],
            ['name' => 'Jacksons (Van Mossel)', 'url' => 'https://www.jacksons.im', 'platform' => 'earthstorm'],
            ['name' => 'IM1 Motors', 'url' => 'https://www.im1.co.im', 'platform' => 'wordpress'],
            ['name' => 'Swift Motors', 'url' => 'https://www.swiftmotors.net', 'platform' => 'cogcms'],
            ['name' => 'Bespoke Group', 'url' => 'http://www.bespokegroup.im', 'platform' => 'bolt'],
            ['name' => 'Manx Car Store', 'url' => 'https://www.manxcarstore.com', 'platform' => 'unknown'],
            ['name' => 'Manx Car Warehouse', 'url' => 'https://www.manxcarwarehouse.im', 'platform' => 'unknown'],
            ['name' => 'Mikes Motors', 'url' => 'https://www.mikesmotors.im', 'platform' => 'unknown'],
            ['name' => 'Vehicles.im', 'url' => 'http://vehicles.im', 'platform' => 'unknown'],
        ];

        foreach ($dealers as $dealer) {
            Dealer::updateOrCreate(
                ['website_url' => $dealer['url']],
                [
                    'name' => $dealer['name'],
                    'platform_type' => $dealer['platform'],
                    'jurisdiction' => 'im',
                    'tier' => 'free',
                    'active' => true,
                ]
            );
        }
    }
}
