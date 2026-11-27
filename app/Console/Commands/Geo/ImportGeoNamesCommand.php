<?php

namespace App\Console\Commands\Geo;

use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

class ImportGeoNamesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geo:import-geonames
                            {--country= : Importer uniquement pour un code pays ISO2 spécifique (ex: CM)}
                            {--limit= : Limiter le nombre de villes importées (utile pour tests)}
                            {--download : Forcer le téléchargement des fichiers depuis geonames.org}
                            {--skip-cities : Importer uniquement les pays}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importe les 250 pays et les ~150 000 villes de plus de 1 000 habitants depuis GeoNames (licence CC BY 4.0)';

    public const COUNTRY_INFO_URL = 'https://download.geonames.org/export/dump/countryInfo.txt';

    public const CITIES_1000_ZIP_URL = 'https://download.geonames.org/export/dump/cities1000.zip';

    public function handle(): int
    {
        $this->info('Début de l\'import GeoNames (Données sous licence CC BY 4.0)');

        $storageDir = storage_path('app/geonames');
        if (! File::isDirectory($storageDir)) {
            File::makeDirectory($storageDir, 0755, true);
        }

        // 1. Import des pays
        $this->importCountries($storageDir);

        if ($this->option('skip-cities')) {
            $this->info('Import des pays terminé (option --skip-cities active).');

            return Command::SUCCESS;
        }

        // 2. Import des villes
        $this->importCities($storageDir);

        $this->info('Import GeoNames terminé avec succès.');

        return Command::SUCCESS;
    }

    /**
     * Télécharge et importe les 250 pays du monde depuis countryInfo.txt.
     */
    protected function importCountries(string $dir): void
    {
        $filePath = $dir.'/countryInfo.txt';

        if (! file_exists($filePath) || $this->option('download')) {
            $this->info('Téléchargement de countryInfo.txt...');
            try {
                $response = Http::timeout(30)->sink($filePath)->get(self::COUNTRY_INFO_URL);
                if (! $response->successful()) {
                    $this->error('Échec du téléchargement de countryInfo.txt');

                    return;
                }
            } catch (\Throwable $e) {
                $this->warn('Impossible de télécharger countryInfo.txt en ligne: '.$e->getMessage().'. Utilisation des pays en base.');

                return;
            }
        }

        $this->info('Traitement de countryInfo.txt...');
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->error("Impossible d'ouvrir {$filePath}");

            return;
        }

        // S'assurer qu'une devise par défaut existe
        $defaultCurrency = Currency::firstOrCreate(
            ['code' => 'XAF'],
            ['name' => 'Franc CFA', 'symbol' => 'FCFA']
        );

        $imported = 0;
        DB::beginTransaction();
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = explode("\t", $line);
                if (count($parts) < 11) {
                    continue;
                }

                $iso2 = strtoupper(trim($parts[0]));
                $iso3 = strtoupper(trim($parts[1]));
                $countryName = trim($parts[4]);
                $phoneCode = ! empty($parts[9]) ? trim($parts[9]) : null;
                $currencyCode = ! empty($parts[10]) ? strtoupper(trim($parts[10])) : 'XAF';

                $currency = Currency::firstOrCreate(
                    ['code' => $currencyCode],
                    ['name' => $currencyCode, 'symbol' => $currencyCode]
                );

                Country::updateOrCreate(
                    ['iso2' => $iso2],
                    [
                        'name' => $countryName,
                        'code' => $iso2,
                        'iso3' => $iso3 ?: $iso2.'X',
                        'phone_code' => $phoneCode,
                        'currency_id' => $currency->id ?: $defaultCurrency->id,
                        'is_active' => true,
                    ]
                );

                $imported++;
            }
            DB::commit();
            fclose($handle);
            $this->info("{$imported} pays importés/mis à jour avec succès.");
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            $this->error("Erreur lors de l'import des pays: ".$e->getMessage());
        }
    }

    /**
     * Télécharge, décompresse et importe le fichier cities1000.txt.
     */
    protected function importCities(string $dir): void
    {
        $zipPath = $dir.'/cities1000.zip';
        $txtPath = $dir.'/cities1000.txt';

        if (! file_exists($txtPath)) {
            if (! file_exists($zipPath) || $this->option('download')) {
                $this->info('Téléchargement de cities1000.zip (~8 Mo)...');
                try {
                    $response = Http::timeout(120)->sink($zipPath)->get(self::CITIES_1000_ZIP_URL);
                    if (! $response->successful()) {
                        $this->error('Échec du téléchargement de cities1000.zip');

                        return;
                    }
                } catch (\Throwable $e) {
                    $this->warn('Impossible de télécharger cities1000.zip en ligne: '.$e->getMessage());

                    return;
                }
            }

            $this->info('Décompression de cities1000.zip...');
            $zip = new ZipArchive;
            if ($zip->open($zipPath) === true) {
                $zip->extractTo($dir);
                $zip->close();
                $this->info('Décompression terminée.');
            } else {
                $this->error('Échec de décompression de cities1000.zip');

                return;
            }
        }

        if (! file_exists($txtPath)) {
            $this->error("Fichier cities1000.txt introuvable dans {$dir}");

            return;
        }

        $this->info('Indexation en mémoire des pays par ISO2...');
        $countriesMap = Country::pluck('id', 'iso2')->all();

        $countryFilter = $this->option('country') ? strtoupper($this->option('country')) : null;
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $handle = fopen($txtPath, 'r');
        if (! $handle) {
            $this->error("Impossible d'ouvrir {$txtPath}");

            return;
        }

        $this->info('Importation des villes...');
        $batch = [];
        $batchSize = 1000;
        $totalImported = 0;
        $existingSlugs = [];

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) < 15) {
                continue;
            }

            $geonameId = (int) $parts[0];
            $cityName = trim($parts[1]);
            $alternateNames = trim($parts[3]);
            $lat = (float) $parts[4];
            $lng = (float) $parts[5];
            $countryIso2 = strtoupper(trim($parts[8]));
            $population = (int) $parts[14];

            if ($countryFilter && $countryIso2 !== $countryFilter) {
                continue;
            }

            $countryId = $countriesMap[$countryIso2] ?? null;
            if (! $countryId) {
                continue;
            }

            // Génération de slug unique basé sur geonameId ou nom
            $slug = Str::slug($cityName).'-'.strtolower($countryIso2).'-'.$geonameId;

            $batch[] = [
                'country_id' => $countryId,
                'name' => $cityName,
                'slug' => $slug,
                'lat' => $lat,
                'lng' => $lng,
                'population' => $population,
                'geoname_id' => $geonameId,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ];

            if (count($batch) >= $batchSize) {
                DB::table('cities')->upsert(
                    $batch,
                    ['slug'],
                    ['name', 'country_id', 'lat', 'lng', 'population', 'geoname_id', 'is_active', 'updated_at']
                );
                $totalImported += count($batch);
                $batch = [];

                if ($limit && $totalImported >= $limit) {
                    break;
                }

                if ($totalImported % 10000 === 0) {
                    $this->line("{$totalImported} villes traitées...");
                }
            }
        }

        if (! empty($batch)) {
            DB::table('cities')->upsert(
                $batch,
                ['slug'],
                ['name', 'country_id', 'lat', 'lng', 'population', 'geoname_id', 'is_active', 'updated_at']
            );
            $totalImported += count($batch);
        }

        fclose($handle);
        $this->info("Importation terminée : {$totalImported} villes importées avec succès.");
    }
}
