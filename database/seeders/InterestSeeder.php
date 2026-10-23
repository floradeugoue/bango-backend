<?php

namespace Database\Seeders;

use App\Models\Profile\Interest;
use Illuminate\Database\Seeder;

class InterestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $interests = [
            [
                'id' => 'realestate',
                'name_fr' => 'Immobilier',
                'name_en' => 'Real Estate',
            ],
            [
                'id' => 'business',
                'name_fr' => 'Business',
                'name_en' => 'Business',
            ],
            [
                'id' => 'sport',
                'name_fr' => 'Sport',
                'name_en' => 'Sports',
            ],
            [
                'id' => 'music',
                'name_fr' => 'Musique',
                'name_en' => 'Music',
            ],
            [
                'id' => 'fashion',
                'name_fr' => 'Mode',
                'name_en' => 'Fashion',
            ],
            [
                'id' => 'food',
                'name_fr' => 'Cuisine',
                'name_en' => 'Food',
            ],
            [
                'id' => 'tech',
                'name_fr' => 'Tech',
                'name_en' => 'Tech',
            ],
            [
                'id' => 'travel',
                'name_fr' => 'Voyage',
                'name_en' => 'Travel',
            ],
            [
                'id' => 'education',
                'name_fr' => 'Éducation',
                'name_en' => 'Education',
            ],
            [
                'id' => 'entertainment',
                'name_fr' => 'Divertissement',
                'name_en' => 'Entertainment',
            ],
        ];

        foreach ($interests as $interest) {
            Interest::updateOrCreate(
                ['id' => $interest['id']],
                $interest
            );
        }
    }
}
