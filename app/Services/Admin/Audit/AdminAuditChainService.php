<?php

namespace App\Services\Admin\Audit;

use App\Models\Admin\AdminAuditExport;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminAuditTamperAttempt;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditChainService
{
    /**
     * Consultation paginée du registre d'audit avec filtres et recherche.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getLogs(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = AdminAuditLog::with(['user:id,name,email']);

        if (! empty($filters['operator_id'])) {
            $query->where('user_id', $filters['operator_id']);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }

        if (! empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('event', 'like', "%{$search}%")
                    ->orWhere('target_label', 'like', "%{$search}%")
                    ->orWhere('target_id', 'like', "%{$search}%")
                    ->orWhere('hash', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        // Tri sur liste blanche
        $allowedSorts = ['id', 'created_at', 'event'];
        $sortField = in_array($filters['sort_by'] ?? '', $allowedSorts, true) ? $filters['sort_by'] : 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortField, $sortOrder);

        return $query->paginate($perPage);
    }

    /**
     * Détail d'un événement d'audit spécifique avec statut cryptographique.
     */
    public function getLog(int|string $id): array
    {
        $log = AdminAuditLog::with(['user:id,name,email', 'tamperAttempts.operator:id,name,email'])->findOrFail($id);

        return [
            'id' => $log->id,
            'operator' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'event' => $log->event,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'target_label' => $log->target_label,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'context' => $log->context,
            'prev_hash' => $log->prev_hash,
            'hash' => $log->hash,
            'is_valid_hash' => $log->verifyHash(),
            'is_tampered' => $log->is_tampered,
            'tamper_attempts_count' => $log->tamperAttempts->count(),
            'tamper_attempts' => $log->tamperAttempts,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * Vérification de l'intégrité de la chaîne SHA-256.
     *
     * @return array{is_valid: bool, total_verified: int, broken_at_id: ?int, reason: ?string}
     */
    public function verifyChain(int $limit = 1000): array
    {
        return AdminAuditLog::verifyChain($limit);
    }

    /**
     * Enregistre une tentative de modification illicite SANS MODIFIER l'événement ciblé.
     *
     * @param  array<string, mixed>  $attemptedChanges
     */
    public function recordTamperAttempt(
        int|string $auditId,
        User $operator,
        array $attemptedChanges,
        ?string $reason = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): AdminAuditTamperAttempt {
        return DB::transaction(function () use ($auditId, $operator, $attemptedChanges, $reason, $ip, $userAgent) {
            $log = AdminAuditLog::findOrFail($auditId);

            // 1. Enregistrement de la tentative dans la table dédiée
            $attempt = AdminAuditTamperAttempt::create([
                'audit_log_id' => $log->id,
                'operator_id' => $operator->id,
                'attempted_changes' => $attemptedChanges,
                'ip_address' => $ip,
                'user_agent' => $userAgent ? substr($userAgent, 0, 500) : null,
                'detection_source' => 'api_attempt',
                'created_at' => now(),
            ]);

            // 2. Journalisation d'une alerte de sécurité chaînée dans le registre
            AdminAuditLog::logChainedEvent(
                event: 'security.audit_tamper_attempt_detected',
                operator: $operator,
                targetType: 'AdminAuditLog',
                targetId: (string) $log->id,
                targetLabel: "Tentative d'altération du log #{$log->id}",
                context: [
                    'tamper_attempt_id' => $attempt->id,
                    'target_log_id' => $log->id,
                    'reason' => $reason,
                    'attempted_changes_keys' => array_keys($attemptedChanges),
                ],
                ip: $ip,
                userAgent: $userAgent
            );

            return $attempt->load(['auditLog', 'operator']);
        });
    }

    /**
     * Prévisualisation du périmètre d'un export d'audit sans générer de fichier.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function previewExport(array $filters): array
    {
        $query = AdminAuditLog::query();

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['operator_id'])) {
            $query->where('user_id', $filters['operator_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $count = $query->count();
        $chainStatus = AdminAuditLog::verifyChain(min($count, 200));

        return [
            'matching_records' => $count,
            'filters_applied' => $filters,
            'estimated_csv_size_kb' => round(($count * 280) / 1024, 2),
            'chain_integrity_sample' => $chainStatus,
            'exportable' => $count > 0,
        ];
    }

    /**
     * Génération d'un export CSV d'audit scellé avec SHA-256 et neutralisation des formules.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportAuditCsv(array $filters, User $operator): StreamedResponse
    {
        $query = AdminAuditLog::with('user:id,name,email')->orderBy('id', 'asc');

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['operator_id'])) {
            $query->where('user_id', $filters['operator_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
        }

        $filename = 'bango_audit_export_'.date('Ymd_His').'.csv';

        // Enregistrer la demande d'export
        $count = (clone $query)->count();
        $sealBasis = "AUDIT_EXPORT|{$operator->id}|".now()->toIso8601String()."|{$count}";
        $sealHash = hash('sha256', $sealBasis);

        AdminAuditExport::create([
            'requester_id' => $operator->id,
            'filename' => $filename,
            'file_path' => "exports/audit/{$filename}",
            'filters' => $filters,
            'records_count' => $count,
            'sha256_seal' => $sealHash,
            'status' => 'completed',
            'expires_at' => now()->addDays(7),
        ]);

        // Journaliser l'export dans l'audit
        AdminAuditLog::logChainedEvent(
            event: 'audit.export_generated',
            operator: $operator,
            targetType: 'AdminAuditExport',
            targetId: $sealHash,
            targetLabel: "Export d'audit scellé ({$count} entrées)",
            context: [
                'filename' => $filename,
                'sha256_seal' => $sealHash,
                'records_count' => $count,
            ]
        );

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-SHA256-Seal' => $sealHash,
        ];

        return response()->stream(function () use ($query, $sealHash) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

            // En-tête de scellement
            fputcsv($handle, ['# BANGO CRYPTOGRAPHIC AUDIT EXPORT', 'SEAL_SHA256: '.$sealHash]);
            fputcsv($handle, [
                'ID',
                'Horodatage (ISO8601)',
                'Opérateur ID',
                'Opérateur Nom',
                'Action / Événement',
                'Type Cible',
                'ID Cible',
                'Label Cible',
                'Adresse IP',
                'Empreinte Précédente (prev_hash)',
                'Empreinte Scellée (hash)',
            ]);

            $query->chunk(500, function ($logs) use ($handle) {
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $this->sanitizeCsvField($log->created_at?->toIso8601String() ?? ''),
                        $log->user_id ?? 'system',
                        $this->sanitizeCsvField($log->user?->name ?? 'Système'),
                        $this->sanitizeCsvField($log->event),
                        $this->sanitizeCsvField($log->target_type ?? ''),
                        $this->sanitizeCsvField($log->target_id ?? ''),
                        $this->sanitizeCsvField($log->target_label ?? ''),
                        $this->sanitizeCsvField($log->ip_address ?? ''),
                        $this->sanitizeCsvField($log->prev_hash ?? ''),
                        $this->sanitizeCsvField($log->hash ?? ''),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Vérification de l'authenticité d'un export selon son scellement SHA-256.
     *
     * @return array<string, mixed>
     */
    public function verifyExportSeal(string $seal): array
    {
        $export = AdminAuditExport::with('requester:id,name,email')
            ->where('sha256_seal', $seal)
            ->first();

        if (! $export) {
            return [
                'is_authentic' => false,
                'message' => 'Scellement inconnu ou fichier d\'export non référencé.',
                'seal' => $seal,
            ];
        }

        return [
            'is_authentic' => true,
            'message' => 'Scellement cryptographique authentifié et intègre.',
            'seal' => $export->sha256_seal,
            'filename' => $export->filename,
            'records_count' => $export->records_count,
            'requester' => $export->requester ? [
                'id' => $export->requester->id,
                'name' => $export->requester->name,
                'email' => $export->requester->email,
            ] : null,
            'created_at' => $export->created_at?->toIso8601String(),
            'is_expired' => $export->isExpired(),
        ];
    }

    /**
     * Neutralise les formules malveillantes pouvant être interprétées par un tableur (CSV Injection).
     */
    protected function sanitizeCsvField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
