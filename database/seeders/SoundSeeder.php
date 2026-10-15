<?php

namespace Database\Seeders;

use App\Models\Reel\Sound;
use Illuminate\Database\Seeder;

class SoundSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sounds = [
            [
                'id' => 'afro-chill',
                'title' => 'Afro Chill',
                'artist' => 'BANGO Sounds',
                'genre' => 'afro',
                'base_uses' => 860,
                'uses_count' => 0,
                'duration_seconds' => 24,
                'is_original' => false,
                'audio_url' => '/sounds/afro-chill.mp3',
            ],
            [
                'id' => 'makossa-sun',
                'title' => 'Makossa Sun',
                'artist' => 'BANGO Sounds',
                'genre' => 'makossa',
                'base_uses' => 412,
                'uses_count' => 0,
                'duration_seconds' => 21,
                'is_original' => false,
                'audio_url' => '/sounds/makossa-sun.mp3',
            ],
            [
                'id' => 'coupe-decale',
                'title' => 'Décalé Gbé',
                'artist' => 'BANGO Sounds',
                'genre' => 'coupe-decale',
                'base_uses' => 1240,
                'uses_count' => 0,
                'duration_seconds' => 18,
                'is_original' => false,
                'audio_url' => '/sounds/coupe-decale.mp3',
            ],
            [
                'id' => 'gospel-morning',
                'title' => 'Gospel Morning',
                'artist' => 'BANGO Sounds',
                'genre' => 'gospel',
                'base_uses' => 203,
                'uses_count' => 0,
                'duration_seconds' => 27,
                'is_original' => false,
                'audio_url' => '/sounds/gospel-morning.mp3',
            ],
            [
                'id' => 'ambiance-market',
                'title' => 'Ambiance marché',
                'artist' => 'BANGO Sounds',
                'genre' => 'ambiance',
                'base_uses' => 95,
                'uses_count' => 0,
                'duration_seconds' => 30,
                'is_original' => false,
                'audio_url' => '/sounds/ambiance-market.mp3',
            ],
            [
                'id' => 'highlife-osu',
                'title' => 'Osu Highlife',
                'artist' => 'BANGO Sounds',
                'genre' => 'highlife',
                'base_uses' => 318,
                'uses_count' => 0,
                'duration_seconds' => 22,
                'is_original' => false,
                'audio_url' => '/sounds/highlife-osu.mp3',
            ],
            [
                'id' => 'lagos-amapiano',
                'title' => 'Lekki Log Drum',
                'artist' => 'BANGO Sounds',
                'genre' => 'amapiano',
                'base_uses' => 977,
                'uses_count' => 0,
                'duration_seconds' => 20,
                'is_original' => false,
                'audio_url' => '/sounds/lagos-amapiano.mp3',
            ],
            [
                'id' => 'kora-calm',
                'title' => 'Kora au calme',
                'artist' => 'BANGO Sounds',
                'genre' => 'traditional',
                'base_uses' => 154,
                'uses_count' => 0,
                'duration_seconds' => 26,
                'is_original' => false,
                'audio_url' => '/sounds/kora-calm.mp3',
            ],
        ];

        foreach ($sounds as $soundData) {
            Sound::updateOrCreate(['id' => $soundData['id']], $soundData);
        }
    }
}
