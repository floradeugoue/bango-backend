<?php

namespace App\Services\Admin\Support;

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketAttachment;
use App\Models\Support\SupportTicketEscalation;
use App\Models\Support\SupportTicketMessage;
use App\Models\User;
use App\Notifications\Support\SupportTicketReplyNotification;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSupportOperationsService
{
    /**
     * 4.1. Liste paginée des tickets avec détection des retards et filtres avancés.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getTickets(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = SupportTicket::with(['user:id,name,display_name,email,phone,avatar_url', 'assignedAgent:id,name,email', 'resolvedBy:id,name,email'])
            ->withCount('messages');

        // Filtre par statut
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filtre par priorité
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        // Filtre par catégorie
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        // Filtre par agent assigné
        if (isset($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'unassigned' || $filters['assigned_to'] === null) {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', (int) $filters['assigned_to']);
            }
        }

        // Recherche textuelle (référence, sujet, email ou nom demandeur)
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('user', function (Builder $uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Filtre tickets hors délai (SLA dépassé : ouverts depuis plus de 24h sans réponse d'agent)
        if (! empty($filters['overdue'])) {
            $overdueThreshold = Carbon::now()->subHours(24);
            $query->whereIn('status', [TicketStatus::Open->value, TicketStatus::InProgress->value])
                ->where(function (Builder $q) use ($overdueThreshold) {
                    $q->whereNull('last_reply_at')->where('created_at', '<', $overdueThreshold)
                        ->orWhere('last_reply_at', '<', $overdueThreshold);
                });
        }

        // Tri sur liste blanche
        $sort = $filters['sort'] ?? 'created_at';
        $order = strtolower($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $allowedSorts = ['created_at', 'updated_at', 'last_reply_at', 'priority', 'status', 'reference'];

        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $order);
        } else {
            $query->orderByDesc('created_at');
        }

        return $query->paginate($perPage);
    }

    /**
     * 4.1 bis. Détail complet d'un ticket pour l'opérateur avec pièces jointes et historique.
     */
    public function getTicketDetails(int|string $id): SupportTicket
    {
        return SupportTicket::with([
            'user:id,name,display_name,email,phone,avatar_url,status,badge,is_verified,is_certified',
            'assignedAgent:id,name,email',
            'resolvedBy:id,name,email',
            'messages' => function ($q) {
                $q->with(['sender:id,name,display_name,email', 'attachments'])
                    ->orderBy('created_at', 'asc');
            },
            'escalations.escalatedBy:id,name,email',
            'escalations.escalatedTo:id,name,email',
            'escalations.answeredBy:id,name,email',
        ])
            ->where(function (Builder $query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id)->orWhere('reference', (string) $id);
                } else {
                    $query->where('reference', (string) $id);
                }
            })
            ->firstOrFail();
    }

    /**
     * 4.2. Réponse publique de l'opérateur à l'utilisateur.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function replyToTicket(
        SupportTicket $ticket,
        User $operator,
        string $messageText,
        ?int $templateId = null,
        array $attachments = []
    ): SupportTicketMessage {
        $storedFiles = [];

        try {
            $message = DB::transaction(function () use ($ticket, $operator, $messageText, $templateId, $attachments, &$storedFiles) {
                $lockedTicket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

                if ($lockedTicket->isClosed()) {
                    throw new DomainException('Ce ticket est clôturé et ne peut plus recevoir de réponse.');
                }

                $metadata = [];
                if ($templateId) {
                    $metadata['template_id'] = $templateId;
                }

                $message = SupportTicketMessage::create([
                    'support_ticket_id' => $lockedTicket->id,
                    'sender_id' => $operator->id,
                    'sender_type' => TicketMessageSenderType::Support,
                    'message' => $messageText,
                    'is_internal' => false,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                ]);

                if (! empty($attachments)) {
                    $this->storeAttachments($lockedTicket, $message, $attachments, $storedFiles);
                }

                $lockedTicket->last_reply_at = Carbon::now();
                if ($lockedTicket->status === TicketStatus::Open) {
                    $lockedTicket->status = TicketStatus::InProgress;
                }
                $lockedTicket->save();

                return $message->load(['attachments', 'sender']);
            });

            // Déclenchement de la notification utilisateur
            try {
                $ticket->user?->notify(new SupportTicketReplyNotification($ticket, $message));
            } catch (\Throwable $e) {
                Log::warning("Notification ticket reply non délivrée : {$e->getMessage()}");
            }

            return $message;
        } catch (\Throwable $e) {
            $this->cleanupFiles($storedFiles);
            throw $e;
        }
    }

    /**
     * 4.3. Ajout d'une note interne strictement confidentielle (conservation 5 ans).
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function addInternalNote(
        SupportTicket $ticket,
        User $operator,
        string $noteText,
        array $attachments = []
    ): SupportTicketMessage {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($ticket, $operator, $noteText, $attachments, &$storedFiles) {
                $lockedTicket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

                $message = SupportTicketMessage::create([
                    'support_ticket_id' => $lockedTicket->id,
                    'sender_id' => $operator->id,
                    'sender_type' => TicketMessageSenderType::InternalNote,
                    'message' => $noteText,
                    'is_internal' => true,
                    'retained_until' => Carbon::now()->addYears(5),
                    'metadata' => [
                        'confidential' => true,
                        'author_role' => $operator->role?->name ?? 'support',
                    ],
                ]);

                if (! empty($attachments)) {
                    $this->storeAttachments($lockedTicket, $message, $attachments, $storedFiles);
                }

                $lockedTicket->last_reply_at = Carbon::now();
                $lockedTicket->save();

                return $message->load(['attachments', 'sender']);
            });
        } catch (\Throwable $e) {
            $this->cleanupFiles($storedFiles);
            throw $e;
        }
    }

    /**
     * 4.4. Assignation ou réassignation unitaire et groupée.
     *
     * @param  array<int>  $ticketIds
     * @return array<string, mixed>
     */
    public function assignTickets(array $ticketIds, int $agentId, User $operator, ?string $reason = null): array
    {
        $agent = User::where('id', $agentId)->where('status', 'active')->firstOrFail();

        $results = [];
        $successCount = 0;

        foreach ($ticketIds as $ticketId) {
            try {
                DB::transaction(function () use ($ticketId, $agent, $operator, $reason, &$results, &$successCount) {
                    $ticket = SupportTicket::where('id', $ticketId)->lockForUpdate()->firstOrFail();

                    if ($ticket->isClosed()) {
                        $results[] = [
                            'ticket_id' => $ticketId,
                            'status' => 'skipped',
                            'message' => 'Le ticket est déjà fermé.',
                        ];

                        return;
                    }

                    $previousAssigneeId = $ticket->assigned_to;
                    $ticket->assigned_to = $agent->id;
                    $ticket->save();

                    // Note interne d'audit sur le changement d'assignation
                    SupportTicketMessage::create([
                        'support_ticket_id' => $ticket->id,
                        'sender_id' => $operator->id,
                        'sender_type' => TicketMessageSenderType::InternalNote,
                        'message' => "Assigné à {$agent->name} (précédent : ".($previousAssigneeId ?: 'aucun').')'.($reason ? " — Motif : {$reason}" : ''),
                        'is_internal' => true,
                        'retained_until' => Carbon::now()->addYears(5),
                    ]);

                    $results[] = [
                        'ticket_id' => $ticketId,
                        'status' => 'assigned',
                        'assigned_to' => $agent->id,
                        'assigned_name' => $agent->name,
                    ];
                    $successCount++;
                });
            } catch (\Throwable $e) {
                $results[] = [
                    'ticket_id' => $ticketId,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'total' => count($ticketIds),
            'assigned_count' => $successCount,
            'target_agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
            ],
            'details' => $results,
        ];
    }

    /**
     * 4.5. Résolution d'un ticket avec motif et catégorie.
     */
    public function resolveTicket(SupportTicket $ticket, User $operator, string $category, string $reason): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $operator, $category, $reason) {
            $lockedTicket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            $lockedTicket->status = TicketStatus::Resolved;
            $lockedTicket->resolved_at = Carbon::now();
            $lockedTicket->resolved_by_id = $operator->id;
            $lockedTicket->resolution_category = $category;
            $lockedTicket->resolution_reason = $reason;
            $lockedTicket->save();

            SupportTicketMessage::create([
                'support_ticket_id' => $lockedTicket->id,
                'sender_id' => $operator->id,
                'sender_type' => TicketMessageSenderType::InternalNote,
                'message' => "Ticket résolu par {$operator->name}. Catégorie : {$category}. Motif : {$reason}",
                'is_internal' => true,
                'retained_until' => Carbon::now()->addYears(5),
            ]);

            return $lockedTicket->fresh(['user', 'assignedAgent', 'resolvedBy']);
        });
    }

    /**
     * 4.5 bis. Réouverture d'un ticket résolu (fenêtre stricte de 7 jours).
     */
    public function reopenTicket(SupportTicket $ticket, User $operator, string $reason): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $operator, $reason) {
            $lockedTicket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

            if (! $lockedTicket->resolved_at) {
                throw new DomainException('Ce ticket n\'est pas à l\'état résolu.');
            }

            // Vérification de la fenêtre de 7 jours
            $diffDays = $lockedTicket->resolved_at->diffInDays(Carbon::now());
            if ($diffDays > 7) {
                throw new DomainException('REOPEN_WINDOW_EXPIRED: Le délai de réouverture de 7 jours après résolution est expiré.');
            }

            $previousResolvedAt = $lockedTicket->resolved_at;
            $lockedTicket->status = TicketStatus::InProgress;
            $lockedTicket->closed_at = null;
            $lockedTicket->save();

            SupportTicketMessage::create([
                'support_ticket_id' => $lockedTicket->id,
                'sender_id' => $operator->id,
                'sender_type' => TicketMessageSenderType::InternalNote,
                'message' => "Ticket réouvert par {$operator->name} (initialement résolu le {$previousResolvedAt->toIso8601String()}). Motif : {$reason}",
                'is_internal' => true,
                'retained_until' => Carbon::now()->addYears(5),
            ]);

            return $lockedTicket->fresh(['user', 'assignedAgent', 'resolvedBy']);
        });
    }

    /**
     * 4.6. Escalade pour avis à une autre équipe ou un superviseur.
     */
    public function escalateTicket(
        SupportTicket $ticket,
        User $operator,
        string $targetTeam,
        ?int $targetUserId,
        string $reason
    ): SupportTicketEscalation {
        return DB::transaction(function () use ($ticket, $operator, $targetTeam, $targetUserId, $reason) {
            $escalation = SupportTicketEscalation::create([
                'support_ticket_id' => $ticket->id,
                'escalated_by_id' => $operator->id,
                'escalated_to_id' => $targetUserId,
                'target_team' => $targetTeam,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_id' => $operator->id,
                'sender_type' => TicketMessageSenderType::InternalNote,
                'message' => "Demande d'avis escaladée vers l'équipe [{$targetTeam}] par {$operator->name}. Motif : {$reason}",
                'is_internal' => true,
                'retained_until' => Carbon::now()->addYears(5),
            ]);

            return $escalation->load(['ticket', 'escalatedBy', 'escalatedTo']);
        });
    }

    /**
     * 4.6 bis. Enregistrement de la réponse à une demande d'escalade.
     */
    public function answerEscalation(int $escalationId, User $operator, string $response): SupportTicketEscalation
    {
        return DB::transaction(function () use ($escalationId, $operator, $response) {
            $escalation = SupportTicketEscalation::where('id', $escalationId)->lockForUpdate()->firstOrFail();

            if ($escalation->status === 'answered') {
                throw new DomainException('Cette demande d\'avis a déjà reçu une réponse.');
            }

            $escalation->response = $response;
            $escalation->answered_by_id = $operator->id;
            $escalation->answered_at = Carbon::now();
            $escalation->status = 'answered';
            $escalation->save();

            SupportTicketMessage::create([
                'support_ticket_id' => $escalation->support_ticket_id,
                'sender_id' => $operator->id,
                'sender_type' => TicketMessageSenderType::InternalNote,
                'message' => "Réponse à l'escalade [{$escalation->target_team}] apportée par {$operator->name} : {$response}",
                'is_internal' => true,
                'retained_until' => Carbon::now()->addYears(5),
            ]);

            return $escalation->load(['ticket', 'escalatedBy', 'answeredBy']);
        });
    }

    /**
     * 4.7. Création manuelle d'un ticket par un opérateur (téléphone, email, etc.).
     *
     * @param  array<string, mixed>  $data
     */
    public function createManualTicket(User $operator, array $data): SupportTicket
    {
        return DB::transaction(function () use ($operator, $data) {
            $userId = ! empty($data['user_id']) ? (int) $data['user_id'] : $operator->id;

            $priority = ! empty($data['priority'])
                ? (TicketPriority::tryFrom($data['priority']) ?? TicketPriority::Normal)
                : TicketPriority::Normal;

            $ticket = SupportTicket::create([
                'reference' => SupportTicket::generateReference(),
                'user_id' => $userId,
                'assigned_to' => ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : $operator->id,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'channel' => $data['channel'] ?? 'phone',
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'last_reply_at' => Carbon::now(),
            ]);

            SupportTicketMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_id' => $operator->id,
                'sender_type' => TicketMessageSenderType::Support,
                'message' => "[Canal: {$ticket->channel}]\n\n".$data['message'],
                'is_internal' => false,
            ]);

            return $ticket->load(['user', 'assignedAgent']);
        });
    }

    /**
     * 4.8. Opérateurs disponibles pour assignation de tickets.
     *
     * @return Collection<int, User>
     */
    public function getAssignees(): Collection
    {
        return User::where('status', 'active')
            ->whereHas('role', function (Builder $q) {
                $q->whereIn('slug', ['support', 'admin', 'direction']);
            })
            ->select(['id', 'name', 'display_name', 'email', 'avatar_url', 'role_id'])
            ->with('role:id,name,slug')
            ->orderBy('name')
            ->get();
    }

    /**
     * 4.8 bis. Équipes et configuration par défaut pour l'escalade d'avis.
     *
     * @return array<string, mixed>
     */
    public function getDefaultEscalation(): array
    {
        return [
            'default_team' => 'supervisors',
            'available_teams' => [
                ['slug' => 'legal', 'name' => 'Équipe Juridique & Conformité', 'description' => 'Litiges contractuels, réclamations réglementaires, réquisitions'],
                ['slug' => 'finance', 'name' => 'Équipe Finance & Rapprochement', 'description' => 'Double débit, litiges Mobile Money, remboursements bloqués'],
                ['slug' => 'tech', 'name' => 'Support Technique & Ingénierie', 'description' => 'Bugs critiques, échecs d\'encodage vidéo, incidents de passerelle'],
                ['slug' => 'moderation', 'name' => 'Équipe Modération & Recours', 'description' => 'Sanctions appliquées, récidives graves, droits d\'auteur'],
                ['slug' => 'supervisors', 'name' => 'Superviseurs Support Niveau 3', 'description' => 'Arbitrages clients VIP et cas complexes hors procédure'],
            ],
            'sla_hours' => [
                'legal' => 48,
                'finance' => 24,
                'tech' => 12,
                'moderation' => 24,
                'supervisors' => 12,
            ],
        ];
    }

    /**
     * 4.8 ter. Statistiques et parts de résolution calculées à partir des données réelles.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getResolutionShares(array $filters): array
    {
        $query = SupportTicket::query();

        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['start_date'])->startOfDay());
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['end_date'])->endOfDay());
        }

        $totalCount = (clone $query)->count();
        $resolvedCount = (clone $query)->whereIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])->count();
        $openCount = (clone $query)->where('status', TicketStatus::Open->value)->count();
        $inProgressCount = (clone $query)->where('status', TicketStatus::InProgress->value)->count();

        // Répartition par catégorie de résolution
        $byCategory = (clone $query)
            ->whereNotNull('resolution_category')
            ->select('resolution_category', DB::raw('count(*) as count'))
            ->groupBy('resolution_category')
            ->pluck('count', 'resolution_category')
            ->toArray();

        // Répartition par priorité
        $byPriority = (clone $query)
            ->select('priority', DB::raw('count(*) as count'))
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        // Délai moyen de résolution en heures
        $resolvedTickets = (clone $query)
            ->whereNotNull('resolved_at')
            ->select(['created_at', 'resolved_at'])
            ->get();

        $avgResolutionHours = 0.0;
        if ($resolvedTickets->isNotEmpty()) {
            $totalHours = $resolvedTickets->reduce(function ($carry, $t) {
                return $carry + Carbon::parse($t->created_at)->diffInHours(Carbon::parse($t->resolved_at));
            }, 0);
            $avgResolutionHours = round($totalHours / $resolvedTickets->count(), 1);
        }

        return [
            'period' => [
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
            'metrics' => [
                'total_tickets' => $totalCount,
                'resolved_tickets' => $resolvedCount,
                'open_tickets' => $openCount,
                'in_progress_tickets' => $inProgressCount,
                'resolution_rate' => $totalCount > 0 ? round(($resolvedCount / $totalCount) * 100, 1) : 0.0,
                'avg_resolution_hours' => $avgResolutionHours,
            ],
            'by_resolution_category' => $byCategory,
            'by_priority' => $byPriority,
        ];
    }

    /**
     * 4.12. Export CSV des tickets avec protection anti-injection de formules.
     *
     * @param  array<string, mixed>  $filters
     */
    public function exportTicketsCsv(array $filters, User $operator): StreamedResponse
    {
        $filename = 'tickets_support_'.date('Ymd_His').'.csv';

        return new StreamedResponse(function () use ($filters) {
            $output = fopen('php://output', 'w');

            // BOM UTF-8 pour Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($output, [
                'Reference',
                'Demandeur_Nom',
                'Demandeur_Email',
                'Sujet',
                'Categorie',
                'Canal',
                'Priorite',
                'Statut',
                'Agent_Assigne',
                'Date_Creation',
                'Date_Derniere_Reponse',
                'Date_Resolution',
                'Categorie_Resolution',
                'Resolut_Par',
            ]);

            $query = SupportTicket::with(['user', 'assignedAgent', 'resolvedBy'])
                ->orderByDesc('created_at');

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['category'])) {
                $query->where('category', $filters['category']);
            }

            $query->chunk(200, function ($tickets) use ($output) {
                foreach ($tickets as $ticket) {
                    fputcsv($output, [
                        $this->sanitizeCsvField($ticket->reference),
                        $this->sanitizeCsvField($ticket->user?->name ?? 'Inconnu'),
                        $this->sanitizeCsvField($ticket->user?->email ?? ''),
                        $this->sanitizeCsvField($ticket->subject),
                        $this->sanitizeCsvField($ticket->category instanceof TicketCategory ? $ticket->category->value : (string) $ticket->category),
                        $this->sanitizeCsvField($ticket->channel ?? 'in_app'),
                        $this->sanitizeCsvField($ticket->priority instanceof TicketPriority ? $ticket->priority->value : (string) $ticket->priority),
                        $this->sanitizeCsvField($ticket->status instanceof TicketStatus ? $ticket->status->value : (string) $ticket->status),
                        $this->sanitizeCsvField($ticket->assignedAgent?->name ?? 'Non assigné'),
                        $ticket->created_at?->toIso8601String() ?? '',
                        $ticket->last_reply_at?->toIso8601String() ?? '',
                        $ticket->resolved_at?->toIso8601String() ?? '',
                        $this->sanitizeCsvField($ticket->resolution_category ?? ''),
                        $this->sanitizeCsvField($ticket->resolvedBy?->name ?? ''),
                    ]);
                }
            });

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Stocke les pièces jointes sur le disque privé.
     *
     * @param  array<int, UploadedFile>  $attachments
     * @param  array<int, string>  $storedFiles
     */
    protected function storeAttachments(
        SupportTicket $ticket,
        SupportTicketMessage $message,
        array $attachments,
        array &$storedFiles
    ): void {
        $disk = 'local';

        foreach ($attachments as $file) {
            $originalName = $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType() ?: $file->getMimeType() ?: 'application/octet-stream';
            $fileSize = $file->getSize();

            $filename = Str::random(32).'.'.$file->guessExtension();
            $directory = "support/tickets/{$ticket->id}";
            $path = $file->storeAs($directory, $filename, $disk);

            $storedFiles[] = $path;

            SupportTicketAttachment::create([
                'support_ticket_id' => $ticket->id,
                'support_ticket_message_id' => $message->id,
                'file_path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'disk' => $disk,
            ]);
        }
    }

    /**
     * Nettoie les fichiers stockés en cas d'erreur.
     *
     * @param  array<int, string>  $storedFiles
     */
    protected function cleanupFiles(array $storedFiles, string $disk = 'local'): void
    {
        foreach ($storedFiles as $path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $e) {
                Log::error("Nettoyage pièce jointe orpheline impossible : {$path}", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Protection anti-injection CSV sur cellules utilisateurs (=, +, -, @, tab, cr).
     */
    protected function sanitizeCsvField(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $trimmed = trim($value);

        if (in_array(substr($trimmed, 0, 1), ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$trimmed;
        }

        return $trimmed;
    }
}
