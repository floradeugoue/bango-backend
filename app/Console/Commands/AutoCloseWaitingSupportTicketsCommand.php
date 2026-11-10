<?php

namespace App\Console\Commands;

use App\Enums\Support\TicketStatus;
use App\Models\Support\SupportTicket;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('support:auto-close-waiting')]
#[Description('Clôture automatiquement les tickets de support en attente de réponse utilisateur depuis N jours.')]
class AutoCloseWaitingSupportTicketsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) config('support.auto_close_days', 5);
        $cutoff = now()->subDays($days);

        $tickets = SupportTicket::query()
            ->where('status', TicketStatus::WaitingForUser)
            ->where('updated_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($tickets as $ticket) {
            $ticket->update([
                'status' => TicketStatus::Closed,
                'closed_at' => now(),
            ]);

            $count++;
        }

        $this->info("{$count} ticket(s) en attente clôturé(s) automatiquement après {$days} jours.");

        return Command::SUCCESS;
    }
}
