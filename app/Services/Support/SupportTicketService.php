<?php

namespace App\Services\Support;

use App\Enums\Support\TicketMessageSenderType;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketAttachment;
use App\Models\Support\SupportTicketMessage;
use App\Models\User;
use App\Notifications\Support\SupportTicketReplyNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportTicketService
{
    /**
     * Crée un ticket de support avec son message initial et pièces jointes éventuelles de façon atomique.
     *
     * @param  array{subject: string, category: string, message: string, priority?: string}  $data
     * @param  array<int, UploadedFile>  $attachments
     */
    public function createTicket(User $user, array $data, array $attachments = []): SupportTicket
    {
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($user, $data, $attachments, &$storedFiles) {
                $priority = isset($data['priority'])
                    ? (TicketPriority::tryFrom($data['priority']) ?? TicketPriority::Normal)
                    : TicketPriority::Normal;

                $ticket = SupportTicket::create([
                    'reference' => SupportTicket::generateReference(),
                    'user_id' => $user->id,
                    'subject' => $data['subject'],
                    'category' => $data['category'],
                    'status' => TicketStatus::Open,
                    'priority' => $priority,
                    'last_reply_at' => now(),
                ]);

                $initialMessage = ! empty($data['incident_id'])
                    ? "[Incident: {$data['incident_id']}]\n\n".$data['message']
                    : $data['message'];

                $message = SupportTicketMessage::create([
                    'support_ticket_id' => $ticket->id,
                    'sender_id' => $user->id,
                    'sender_type' => TicketMessageSenderType::Client,
                    'message' => $initialMessage,
                    'is_internal' => false,
                ]);

                if (! empty($attachments)) {
                    $this->storeAttachments($ticket, $message, $attachments, $storedFiles);
                }

                return $ticket->load(['messages.attachments', 'user']);
            });
        } catch (\Throwable $e) {
            // Nettoyer les fichiers orphelins en cas d'échec de la transaction
            $this->cleanupFiles($storedFiles);
            throw $e;
        }
    }

    /**
     * Ajoute une réponse ou une note interne à un ticket.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function replyTicket(
        SupportTicket $ticket,
        User $user,
        string $messageContent,
        array $attachments = [],
        bool $isInternal = false
    ): SupportTicketMessage {
        $storedFiles = [];

        try {
            $message = DB::transaction(function () use ($ticket, $user, $messageContent, $attachments, $isInternal, &$storedFiles) {
                // Verrouiller la ligne du ticket
                $ticket = SupportTicket::where('id', $ticket->id)->lockForUpdate()->firstOrFail();

                $isAgent = $user->isSupportAgent();

                if (! $isAgent && $ticket->isClosed()) {
                    throw new \DomainException('Ce ticket est fermé et ne peut plus recevoir de réponses.');
                }

                if ($isInternal && ! $isAgent) {
                    throw new \DomainException('Seuls les agents de support peuvent rédiger des notes internes.');
                }

                $senderType = match (true) {
                    $isInternal => TicketMessageSenderType::InternalNote,
                    $isAgent => TicketMessageSenderType::Support,
                    default => TicketMessageSenderType::Client,
                };

                $message = SupportTicketMessage::create([
                    'support_ticket_id' => $ticket->id,
                    'sender_id' => $user->id,
                    'sender_type' => $senderType,
                    'message' => $messageContent,
                    'is_internal' => $isInternal,
                ]);

                if (! empty($attachments)) {
                    $this->storeAttachments($ticket, $message, $attachments, $storedFiles);
                }

                $ticket->last_reply_at = now();

                // Transitions automatiques de statut
                if (! $isInternal) {
                    if ($senderType === TicketMessageSenderType::Client) {
                        if (in_array($ticket->status, [TicketStatus::WaitingForUser, TicketStatus::Resolved], true)) {
                            $ticket->status = TicketStatus::InProgress;
                        }
                    } elseif ($senderType === TicketMessageSenderType::Support) {
                        if ($ticket->status === TicketStatus::Open) {
                            $ticket->status = TicketStatus::InProgress;
                        }
                    }
                }

                $ticket->save();

                return $message->load('attachments');
            });

            // Envoi de la notification hors transaction pour résilience
            $this->notifyReplyParticipants($ticket, $message, $isInternal);

            return $message;
        } catch (\Throwable $e) {
            $this->cleanupFiles($storedFiles);
            throw $e;
        }
    }

    /**
     * Met à jour le statut d'un ticket.
     */
    public function updateStatus(SupportTicket $ticket, TicketStatus $status, ?User $agent = null): SupportTicket
    {
        $ticket->status = $status;

        if ($status === TicketStatus::Resolved) {
            $ticket->resolved_at = now();
        } elseif ($status === TicketStatus::Closed) {
            $ticket->closed_at = now();
        }

        $ticket->save();

        return $ticket;
    }

    /**
     * Assigne un ticket à un agent support.
     */
    public function assignTicket(SupportTicket $ticket, ?User $agent): SupportTicket
    {
        $ticket->assigned_to = $agent?->id;
        $ticket->save();

        return $ticket;
    }

    /**
     * Liste paginée des tickets d'un utilisateur.
     *
     * @param  array{status?: ?string}  $filters
     */
    public function listUserTickets(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 50);

        return SupportTicket::query()
            ->forUser($user->id)
            ->status($filters['status'] ?? null)
            ->withCount('messages')
            ->orderBy('last_reply_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Liste paginée des tickets pour le back-office / support.
     *
     * @param  array{status?: ?string, priority?: ?string, category?: ?string, assigned_to?: ?int}  $filters
     */
    public function listAdminTickets(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = SupportTicket::query()
            ->with(['user', 'assignedAgent'])
            ->withCount('messages');

        if (! empty($filters['status'])) {
            $query->status($filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Récupère les messages d'un ticket avec filtrage strict des notes internes pour les clients.
     *
     * @return Collection<int, SupportTicketMessage>
     */
    public function getAuthorizedMessages(SupportTicket $ticket, User $user)
    {
        $query = $ticket->messages()->with(['attachments', 'sender']);

        if (! $user->isSupportAgent()) {
            $query->where('is_internal', false);
        }

        return $query->get();
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
     * Notifie les participants lors d'une réponse.
     */
    protected function notifyReplyParticipants(
        SupportTicket $ticket,
        SupportTicketMessage $message,
        bool $isInternal
    ): void {
        if ($isInternal) {
            return;
        }

        try {
            if ($message->sender_type === TicketMessageSenderType::Support) {
                // Notifier le client
                $ticket->user?->notify(new SupportTicketReplyNotification($ticket, $message));
            } elseif ($message->sender_type === TicketMessageSenderType::Client) {
                // Notifier l'agent assigné s'il existe
                $ticket->assignedAgent?->notify(new SupportTicketReplyNotification($ticket, $message));
            }
        } catch (\Throwable $e) {
            // Une erreur de notification ne doit jamais faire échouer la réponse
            Log::warning('Échec de notification ticket reply: '.$e->getMessage(), [
                'ticket_id' => $ticket->id,
                'message_id' => $message->id,
            ]);
        }
    }

    /**
     * Nettoie les fichiers orphelins en cas d'erreur.
     *
     * @param  array<int, string>  $storedFiles
     */
    protected function cleanupFiles(array $storedFiles, string $disk = 'local'): void
    {
        foreach ($storedFiles as $path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $e) {
                Log::error('Échec de nettoyage fichier support orphelin: '.$path, ['error' => $e->getMessage()]);
            }
        }
    }
}
