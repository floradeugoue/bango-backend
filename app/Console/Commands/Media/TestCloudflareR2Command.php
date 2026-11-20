<?php

namespace App\Console\Commands\Media;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TestCloudflareR2Command extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'media:test-r2';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Teste de bout en bout la connectivité avec Cloudflare R2 (disques public et privé)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== TEST DE BOUT EN BOUT CLOUDFLARE R2 ===');
        $this->newLine();

        $allOk = true;

        // 1. Test du disque public (r2)
        $this->info('1. Test du disque public [r2] (Bucket: '.config('filesystems.disks.r2.bucket').')...');
        try {
            $r2 = Storage::disk('r2');
            $testPath = 'tests/probe_r2_'.time().'.txt';
            $testContent = 'BANGO Cloudflare R2 Public Test - '.now()->toIso8601String();

            // Écriture
            $this->line('   → Écriture du fichier : '.$testPath);
            $r2->put($testPath, $testContent);

            // Lecture
            $this->line('   → Lecture du contenu...');
            $readContent = $r2->get($testPath);
            if ($readContent !== $testContent) {
                throw new \RuntimeException('Le contenu lu ne correspond pas au contenu écrit.');
            }
            $this->line('     Contenu vérifié avec succès.');

            // URL publique
            $url = $r2->url($testPath);
            $this->line('   → URL publique générée : <comment>'.$url.'</comment>');

            $expectedPrefix = rtrim(config('filesystems.disks.r2.url', ''), '/');
            if (! empty($expectedPrefix) && ! str_starts_with($url, $expectedPrefix)) {
                $this->warn("     Attention: l'URL générée ne commence pas par {$expectedPrefix}");
            } else {
                $this->line('     Préfixe CDN vérifié.');
            }

            // Suppression
            $this->line('   → Suppression du fichier test...');
            $r2->delete($testPath);

            if ($r2->exists($testPath)) {
                throw new \RuntimeException('Le fichier existe encore après la suppression.');
            }

            $this->info('   ✔ Disque public [r2] : SUCCÈS');
        } catch (Throwable $e) {
            $allOk = false;
            $this->error('   ❌ Échec sur le disque public [r2] : '.$e->getMessage());
        }

        $this->newLine();

        // 2. Test du disque privé (r2_private)
        $this->info('2. Test du disque privé [r2_private] (Bucket: '.config('filesystems.disks.r2_private.bucket').')...');
        try {
            $r2Private = Storage::disk('r2_private');
            $privateTestPath = 'tests/private_probe_'.time().'.txt';
            $privateContent = 'BANGO Cloudflare R2 Private Secret Test - '.now()->toIso8601String();

            // Écriture
            $this->line('   → Écriture du fichier privé : '.$privateTestPath);
            $r2Private->put($privateTestPath, $privateContent);

            // Lecture
            $this->line('   → Lecture du contenu sécurisé...');
            $readPrivateContent = $r2Private->get($privateTestPath);
            if ($readPrivateContent !== $privateContent) {
                throw new \RuntimeException('Le contenu lu ne correspond pas au contenu privé.');
            }
            $this->line('     Contenu privé vérifié avec succès.');

            // URL temporaire signée
            $this->line('   → Génération de l\'URL signée temporaire (10 minutes)...');
            $tempUrl = $r2Private->temporaryUrl($privateTestPath, now()->addMinutes(10));
            $this->line('   → URL signée : <comment>'.substr($tempUrl, 0, 95).'...</comment>');

            if (! str_contains($tempUrl, 'X-Amz-Signature=')) {
                $this->warn('     Attention: l\'URL ne semble pas contenir de signature AWS v4.');
            } else {
                $this->line('     Signature AWS v4 validée.');
            }

            // Suppression
            $this->line('   → Suppression du fichier privé...');
            $r2Private->delete($privateTestPath);

            if ($r2Private->exists($privateTestPath)) {
                throw new \RuntimeException('Le fichier privé existe encore après la suppression.');
            }

            $this->info('   ✔ Disque privé [r2_private] : SUCCÈS');
        } catch (Throwable $e) {
            $allOk = false;
            $this->error('   ❌ Échec sur le disque privé [r2_private] : '.$e->getMessage());
        }

        $this->newLine();
        if ($allOk) {
            $this->info('🎉 Tous les tests de connectivité Cloudflare R2 ont réussi !');

            return self::SUCCESS;
        }

        $this->error('Certains tests Cloudflare R2 ont échoué.');

        return self::FAILURE;
    }
}
