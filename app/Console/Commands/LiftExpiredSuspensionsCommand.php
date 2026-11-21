<?php

namespace App\Console\Commands;

use App\Models\Support\UserAccountSanction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:lift-expired-suspensions')]
#[Description('Lève automatiquement le statut suspendu des utilisateurs dont la date de fin de suspension temporaire est échue.')]
class LiftExpiredSuspensionsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $now = Carbon::now();

        $users = User::query()
            ->where(function ($query) use ($now) {
                $query->where('status', 'suspended')
                    ->whereNotNull('suspended_until')
                    ->where('suspended_until', '<=', $now);
            })
            ->orWhere(function ($query) use ($now) {
                $query->whereNotNull('suspended_until')
                    ->where('suspended_until', '<=', $now);
            })
            ->get();

        $count = 0;
        foreach ($users as $user) {
            $user->update([
                'status' => 'active',
                'suspension_reason' => null,
                'suspended_from' => null,
                'suspended_until' => null,
            ]);

            UserAccountSanction::where('user_id', $user->id)
                ->where('type', 'suspension')
                ->where('is_active', true)
                ->where(function ($query) use ($now) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '<=', $now);
                })
                ->update([
                    'is_active' => false,
                    'lifted_at' => $now,
                    'lift_reason' => 'Fin de suspension temporaire échue.',
                ]);

            $count++;
        }

        // Clôturer également toute sanction orpheline échue
        UserAccountSanction::where('type', 'suspension')
            ->where('is_active', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->update([
                'is_active' => false,
                'lifted_at' => $now,
                'lift_reason' => 'Fin de suspension temporaire échue.',
            ]);

        $this->info("{$count} suspension(s) échue(s) levée(s) avec succès.");

        return Command::SUCCESS;
    }
}
