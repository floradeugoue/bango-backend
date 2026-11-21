<?php

namespace App\Services\Admin\Stats;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminActivityStatsService
{
    /**
     * Calcule l'ensemble des indicateurs de l'écran Activité.
     *
     * @return array{
     *     active: array{day: int, week: int, month: int},
     *     days: array<int, array{date: string, active_users: int, signups: int, posts: int, stories: int, reels: int, messages: int, visits_booked: int, revenue: int}>,
     *     retention: array{cohort_size: int, d1: float|null, d7: float|null, d30: float|null},
     *     signup_funnel: array<int, array{step: string, count: int}>
     * }
     */
    public function getActivityStats(Carbon $from, Carbon $to, ?string $country = null): array
    {
        return [
            'active' => $this->getActiveUsers($to, $country),
            'days' => $this->getDaysBreakdown($from, $to, $country),
            'retention' => $this->getRetention($from, $to, $country),
            'signup_funnel' => $this->getSignupFunnel($from, $to, $country),
        ];
    }

    /**
     * Comptes distincts ayant eu une activité authentifiée dans les dernières 24h, 7j et 30j à la fin de la période.
     *
     * @return array{day: int, week: int, month: int}
     */
    public function getActiveUsers(Carbon $endDate, ?string $country = null): array
    {
        $dayDate = $endDate->toDateString();
        $weekStartDate = $endDate->copy()->subDays(6)->toDateString();
        $monthStartDate = $endDate->copy()->subDays(29)->toDateString();

        $baseQuery = DB::table('user_daily_activity');

        if ($country) {
            $code = strtoupper(trim($country));
            $baseQuery->join('users', 'users.id', '=', 'user_daily_activity.user_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }

        $dayCount = (clone $baseQuery)
            ->whereDate('user_daily_activity.date', $dayDate)
            ->distinct()
            ->count('user_daily_activity.user_id');

        $weekCount = (clone $baseQuery)
            ->whereDate('user_daily_activity.date', '>=', $weekStartDate)
            ->whereDate('user_daily_activity.date', '<=', $dayDate)
            ->distinct()
            ->count('user_daily_activity.user_id');

        $monthCount = (clone $baseQuery)
            ->whereDate('user_daily_activity.date', '>=', $monthStartDate)
            ->whereDate('user_daily_activity.date', '<=', $dayDate)
            ->distinct()
            ->count('user_daily_activity.user_id');

        return [
            'day' => $dayCount,
            'week' => $weekCount,
            'month' => $monthCount,
        ];
    }

    /**
     * Découpage journalier des métriques d'activité et de revenus.
     *
     * @return array<int, array{date: string, active_users: int, signups: int, posts: int, stories: int, reels: int, messages: int, visits_booked: int, revenue: int}>
     */
    public function getDaysBreakdown(Carbon $from, Carbon $to, ?string $country = null): array
    {
        $code = $country ? strtoupper(trim($country)) : null;

        // Génération continue de tous les jours de la période (y compris jours à 0)
        $current = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $dates = [];
        while ($current->lte($end)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        $fromStart = $from->copy()->startOfDay();
        $toEnd = $to->copy()->endOfDay();

        // 1. Utilisateurs actifs
        $activityQuery = DB::table('user_daily_activity')
            ->whereDate('user_daily_activity.date', '>=', $from->toDateString())
            ->whereDate('user_daily_activity.date', '<=', $to->toDateString());
        if ($code) {
            $activityQuery->join('users', 'users.id', '=', 'user_daily_activity.user_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $activeByDate = $activityQuery
            ->select(DB::raw('DATE(user_daily_activity.date) as date_val'), DB::raw('count(distinct user_daily_activity.user_id) as total'))
            ->groupBy(DB::raw('DATE(user_daily_activity.date)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 2. Inscriptions
        $signupsQuery = DB::table('users')
            ->whereBetween('users.created_at', [$fromStart, $toEnd]);
        if ($code) {
            $signupsQuery->where(function ($q) use ($code) {
                $q->where('users.country_code', $code)
                    ->orWhereExists(function ($sub) use ($code) {
                        $sub->select(DB::raw(1))
                            ->from('countries')
                            ->whereColumn('countries.id', 'users.country_id')
                            ->where('countries.code', $code);
                    });
            });
        }
        $signupsByDate = $signupsQuery
            ->select(DB::raw('DATE(users.created_at) as date_val'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(users.created_at)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 3. Posts
        $postsQuery = DB::table('posts')
            ->whereBetween('posts.created_at', [$fromStart, $toEnd]);
        if ($code) {
            $postsQuery->join('users', 'users.id', '=', 'posts.user_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $postsByDate = $postsQuery
            ->select(DB::raw('DATE(posts.created_at) as date_val'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(posts.created_at)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 4. Stories
        $storiesQuery = DB::table('stories')
            ->whereBetween('stories.created_at', [$fromStart, $toEnd]);
        if ($code) {
            $storiesQuery->join('users', 'users.id', '=', 'stories.user_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $storiesByDate = $storiesQuery
            ->select(DB::raw('DATE(stories.created_at) as date_val'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(stories.created_at)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 5. Reels
        $reelsQuery = DB::table('reels')
            ->whereBetween('reels.created_at', [$fromStart, $toEnd]);
        if ($code) {
            $reelsQuery->join('users', 'users.id', '=', 'reels.user_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $reelsByDate = $reelsQuery
            ->select(DB::raw('DATE(reels.created_at) as date_val'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(reels.created_at)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 6. Messages
        $messagesQuery = DB::table('messages')
            ->whereBetween('messages.created_at', [$fromStart, $toEnd]);
        if ($code) {
            $messagesQuery->join('users', 'users.id', '=', 'messages.sender_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $messagesByDate = $messagesQuery
            ->select(DB::raw('DATE(messages.created_at) as date_val'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(messages.created_at)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 7. Visites réservées
        $visitsQuery = DB::table('visit_reservations')
            ->whereBetween('visit_reservations.created_at', [$fromStart, $toEnd]);
        if ($code) {
            $visitsQuery->join('users', 'users.id', '=', 'visit_reservations.prospect_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $visitsByDate = $visitsQuery
            ->select(DB::raw('DATE(visit_reservations.created_at) as date_val'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(visit_reservations.created_at)'))
            ->pluck('total', 'date_val')
            ->toArray();

        // 8. Chiffre d'affaires encaissé (visites, badges, boosts, certifications), hors crédit BANGO, en XAF
        $revenueQuery = DB::table('payment_transactions')
            ->whereIn('payment_transactions.status', ['confirmed', 'successful'])
            ->whereNotIn('payment_transactions.provider', ['credit', 'wallet'])
            ->whereBetween(DB::raw('COALESCE(payment_transactions.confirmed_at, payment_transactions.created_at)'), [$fromStart, $toEnd]);
        if ($code) {
            $revenueQuery->join('users', 'users.id', '=', 'payment_transactions.user_id')
                ->where(function ($q) use ($code) {
                    $q->where('users.country_code', $code)
                        ->orWhereExists(function ($sub) use ($code) {
                            $sub->select(DB::raw(1))
                                ->from('countries')
                                ->whereColumn('countries.id', 'users.country_id')
                                ->where('countries.code', $code);
                        });
                });
        }
        $revenueByDate = $revenueQuery
            ->select(
                DB::raw('DATE(COALESCE(payment_transactions.confirmed_at, payment_transactions.created_at)) as date_val'),
                DB::raw('sum(COALESCE(payment_transactions.amount_charged, payment_transactions.amount)) as total')
            )
            ->groupBy(DB::raw('DATE(COALESCE(payment_transactions.confirmed_at, payment_transactions.created_at))'))
            ->pluck('total', 'date_val')
            ->toArray();

        $days = [];
        foreach ($dates as $date) {
            $days[] = [
                'date' => $date,
                'active_users' => (int) ($activeByDate[$date] ?? 0),
                'signups' => (int) ($signupsByDate[$date] ?? 0),
                'posts' => (int) ($postsByDate[$date] ?? 0),
                'stories' => (int) ($storiesByDate[$date] ?? 0),
                'reels' => (int) ($reelsByDate[$date] ?? 0),
                'messages' => (int) ($messagesByDate[$date] ?? 0),
                'visits_booked' => (int) ($visitsByDate[$date] ?? 0),
                'revenue' => (int) round((float) ($revenueByDate[$date] ?? 0)),
            ];
        }

        return $days;
    }

    /**
     * Taux de rétention de la cohorte d'inscrits à J+1, J+7 et J+30.
     *
     * @return array{cohort_size: int, d1: float|null, d7: float|null, d30: float|null}
     */
    public function getRetention(Carbon $from, Carbon $to, ?string $country = null): array
    {
        $cohortQuery = DB::table('users')
            ->whereBetween('users.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($country) {
            $code = strtoupper(trim($country));
            $cohortQuery->where(function ($q) use ($code) {
                $q->where('users.country_code', $code)
                    ->orWhereExists(function ($sub) use ($code) {
                        $sub->select(DB::raw(1))
                            ->from('countries')
                            ->whereColumn('countries.id', 'users.country_id')
                            ->where('countries.code', $code);
                    });
            });
        }

        $users = $cohortQuery->select('users.id', DB::raw('DATE(users.created_at) as signup_date'))->get();
        $cohortSize = $users->count();

        if ($cohortSize === 0) {
            return [
                'cohort_size' => 0,
                'd1' => null,
                'd7' => null,
                'd30' => null,
            ];
        }

        $today = Carbon::now('UTC')->toDateString();

        $calcForDays = function (int $offsetDays) use ($users, $today) {
            $eligibleUsers = $users->filter(function ($u) use ($offsetDays, $today) {
                $targetDate = Carbon::parse($u->signup_date, 'UTC')->addDays($offsetDays)->toDateString();

                return $targetDate <= $today;
            });

            if ($eligibleUsers->isEmpty()) {
                return null;
            }

            $eligibleIds = $eligibleUsers->pluck('id')->all();

            $targetDatesByUser = [];
            foreach ($eligibleUsers as $u) {
                $targetDatesByUser[$u->id] = Carbon::parse($u->signup_date, 'UTC')->addDays($offsetDays)->toDateString();
            }

            $activities = DB::table('user_daily_activity')
                ->whereIn('user_id', $eligibleIds)
                ->select('user_id', DB::raw('DATE(date) as date_val'))
                ->get();

            $activeCount = 0;
            foreach ($eligibleUsers as $u) {
                $expectedDate = $targetDatesByUser[$u->id];
                $hasActive = $activities->first(
                    fn ($act) => (int) $act->user_id === (int) $u->id && (string) $act->date_val === (string) $expectedDate
                );
                if ($hasActive) {
                    $activeCount++;
                }
            }

            return round($activeCount / $eligibleUsers->count(), 2);
        };

        return [
            'cohort_size' => $cohortSize,
            'd1' => $calcForDays(1),
            'd7' => $calcForDays(7),
            'd30' => $calcForDays(30),
        ];
    }

    /**
     * Entonnoir de conversion de l'inscription pour la cohorte créée sur la période.
     *
     * @return array<int, array{step: string, count: int}>
     */
    public function getSignupFunnel(Carbon $from, Carbon $to, ?string $country = null): array
    {
        $base = User::query()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if ($country) {
            $code = strtoupper(trim($country));
            $base->where(function ($q) use ($code) {
                $q->where('country_code', $code)
                    ->orWhereHas('country', fn ($sub) => $sub->where('code', $code));
            });
        }

        $accountCount = (clone $base)->count();

        $identityCount = (clone $base)->where(function ($q) {
            $q->where(function ($sub) {
                $sub->whereNotNull('birth_date')
                    ->whereNotNull('gender')
                    ->where(function ($c) {
                        $c->whereNotNull('country_id')->orWhereNotNull('country_code');
                    })
                    ->where(function ($loc) {
                        $loc->whereNotNull('neighbourhood')
                            ->orWhereNotNull('neighbourhood_id')
                            ->orWhereNotNull('city')
                            ->orWhereNotNull('city_id');
                    });
            })->orWhereJsonContains('onboarding_progress', 'identity');
        })->count();

        $phoneCount = (clone $base)->where(function ($q) {
            $q->whereNotNull('phone_verified_at')
                ->orWhereJsonContains('onboarding_progress', 'phone');
        })->count();

        $profileCount = (clone $base)->where(function ($q) {
            $q->where(function ($sub) {
                $sub->whereNotNull('avatar_url')
                    ->whereNotNull('name')
                    ->whereNotNull('handle');
            })->orWhereJsonContains('onboarding_progress', 'profile');
        })->count();

        $interestsCount = (clone $base)->where(function ($q) {
            $q->whereHas('interests')
                ->orWhereJsonContains('onboarding_progress', 'interests');
        })->count();

        $housingCount = (clone $base)->where(function ($q) {
            $q->whereHas('housingSearch')
                ->orWhereJsonContains('onboarding_progress', 'housing');
        })->count();

        $squareCount = (clone $base)->where(function ($q) {
            $q->whereJsonContains('onboarding_progress', 'square')
                ->orWhere(function ($sub) {
                    $sub->whereNotNull('phone_verified_at')
                        ->whereNotNull('avatar_url')
                        ->whereHas('interests')
                        ->whereHas('housingSearch');
                });
        })->count();

        return [
            ['step' => 'account', 'count' => $accountCount],
            ['step' => 'identity', 'count' => $identityCount],
            ['step' => 'phone', 'count' => $phoneCount],
            ['step' => 'profile', 'count' => $profileCount],
            ['step' => 'interests', 'count' => $interestsCount],
            ['step' => 'housing', 'count' => $housingCount],
            ['step' => 'square', 'count' => $squareCount],
        ];
    }
}
