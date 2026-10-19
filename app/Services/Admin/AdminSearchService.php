<?php

namespace App\Services\Admin;

use App\Enums\Admin\AdminPermission;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Support\SupportTicket;
use App\Models\User;

class AdminSearchService
{
    /**
     * Recherche transversale (⌘K) multi-entités sécurisée et respectant les permissions.
     *
     * @return array<string, mixed>
     */
    public function search(User $operator, string $query, ?string $category = 'all', int $limit = 20): array
    {
        $cleanQuery = trim($query);
        $results = [];

        // 1. Catégorie : COMPTES (nécessite rôle admin ou permission moderation:view ou support:view)
        if (($category === 'all' || $category === 'comptes') &&
            ($operator->isAdmin() || $operator->hasPermission(AdminPermission::ModerationView->value) || $operator->hasPermission(AdminPermission::SupportView->value))) {

            $users = User::query()
                ->select(['id', 'name', 'email', 'handle', 'status', 'created_at'])
                ->where(function ($q) use ($cleanQuery) {
                    $q->where('name', 'like', "%{$cleanQuery}%")
                        ->orWhere('email', 'like', "%{$cleanQuery}%")
                        ->orWhere('handle', 'like', "%{$cleanQuery}%");
                })
                ->limit($limit)
                ->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'type' => 'compte',
                    'title' => $u->name,
                    'subtitle' => $u->email,
                    'handle' => $u->handle ? '@'.$u->handle : null,
                    'status' => $u->status ?? 'active',
                ]);

            $results['comptes'] = $users;
        }

        // 2. Catégorie : ANNONCES (nécessite modération ou admin)
        if (($category === 'all' || $category === 'annonces') &&
            ($operator->isAdmin() || $operator->hasPermission(AdminPermission::ModerationView->value))) {

            $listings = Listing::query()
                ->select(['id', 'title', 'price', 'currency', 'status', 'created_at'])
                ->where(function ($q) use ($cleanQuery) {
                    $q->where('title', 'like', "%{$cleanQuery}%")
                        ->orWhere('id', 'like', "%{$cleanQuery}%");
                })
                ->limit($limit)
                ->get()
                ->map(fn (Listing $l) => [
                    'id' => $l->id,
                    'type' => 'annonce',
                    'title' => $l->title,
                    'subtitle' => $l->price.' '.($l->currency ?? 'XAF'),
                    'status' => $l->status,
                ]);

            $results['annonces'] = $listings;
        }

        // 3. Catégorie : PAIEMENTS (nécessite finance ou admin)
        if (($category === 'all' || $category === 'paiements') &&
            ($operator->isAdmin() || $operator->hasPermission(AdminPermission::FinanceView->value))) {

            $payments = PaymentTransaction::query()
                ->select(['id', 'reference', 'amount', 'currency', 'status', 'channel', 'provider', 'created_at'])
                ->where(function ($q) use ($cleanQuery) {
                    $q->where('reference', 'like', "%{$cleanQuery}%")
                        ->orWhere('id', 'like', "%{$cleanQuery}%");
                })
                ->limit($limit)
                ->get()
                ->map(fn (PaymentTransaction $p) => [
                    'id' => $p->id,
                    'type' => 'paiement',
                    'title' => 'Réf: '.$p->reference,
                    'subtitle' => $p->amount.' '.$p->currency.' ('.($p->channel?->value ?? $p->provider?->value ?? 'N/A').')',
                    'status' => $p->status,
                ]);

            $results['paiements'] = $payments;
        }

        // 4. Catégorie : TICKETS (nécessite support ou admin)
        if (($category === 'all' || $category === 'tickets') &&
            ($operator->isAdmin() || $operator->hasPermission(AdminPermission::SupportView->value))) {

            $tickets = SupportTicket::query()
                ->select(['id', 'reference', 'subject', 'category', 'status', 'priority', 'created_at'])
                ->where(function ($q) use ($cleanQuery) {
                    $q->where('reference', 'like', "%{$cleanQuery}%")
                        ->orWhere('subject', 'like', "%{$cleanQuery}%");
                })
                ->limit($limit)
                ->get()
                ->map(fn (SupportTicket $t) => [
                    'id' => $t->id,
                    'type' => 'ticket',
                    'title' => $t->reference.' — '.$t->subject,
                    'subtitle' => 'Priorité: '.$t->priority->value.' | Statut: '.$t->status->value,
                    'status' => $t->status->value,
                ]);

            $results['tickets'] = $tickets;
        }

        $totalFound = array_reduce($results, fn ($carry, $items) => $carry + count($items), 0);

        return [
            'query' => $cleanQuery,
            'category' => $category,
            'total_found' => $totalFound,
            'results' => $results,
        ];
    }
}
