<?php

namespace App\Console\Commands\Admin;

use App\Models\Admin\AdminAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResealAuditChainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:audit:reseal {--force : Forcer le rescellement même en environnement de production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalcule et rescelle les empreintes SHA-256 de la chaîne d\'audit en environnement de développement ou suite à une migration manuelle.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Cette commande ne doit pas être exécutée en production sans l\'option --force car elle altère l\'immuabilité historique.');

            return Command::FAILURE;
        }

        $logs = AdminAuditLog::orderBy('id', 'asc')->get();

        if ($logs->isEmpty()) {
            $this->info('Aucun journal d\'audit à resceller.');

            return Command::SUCCESS;
        }

        $this->info("Début du rescellement cryptographique de {$logs->count()} enregistrements d'audit...");

        DB::transaction(function () use ($logs) {
            $prevHash = AdminAuditLog::GENESIS_PREV_HASH;

            foreach ($logs as $log) {
                $targetStr = (! empty($log->target_label)) ? $log->target_label : ((! empty($log->target_type)) ? "{$log->target_type}:{$log->target_id}" : 'system');
                $operatorStr = (! empty($log->user_id)) ? (string) $log->user_id : 'system';
                $timestamp = $log->created_at ? $log->created_at->toIso8601String() : '';

                $computedHash = AdminAuditLog::computeHash(
                    prevHash: $prevHash,
                    id: $log->id,
                    timestamp: $timestamp,
                    action: $log->event,
                    target: $targetStr,
                    operator: $operatorStr
                );

                // Mise à jour directe au niveau SQL pour contourner le verrou d'immuabilité booted()
                DB::table('admin_audit_logs')->where('id', $log->id)->update([
                    'prev_hash' => $prevHash,
                    'hash' => $computedHash,
                    'is_tampered' => false,
                ]);

                $prevHash = $computedHash;
            }
        });

        $verification = AdminAuditLog::verifyChain();

        if ($verification['is_valid']) {
            $this->info("Chaîne d'audit rescellée avec succès ({$verification['total_verified']} blocs vérifiés, statut : VALIDE).");

            return Command::SUCCESS;
        }

        $this->error("Échec de la validation après rescellement : {$verification['reason']}");

        return Command::FAILURE;
    }
}
