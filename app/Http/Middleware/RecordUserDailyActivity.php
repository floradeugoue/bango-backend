<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RecordUserDailyActivity
{
    /**
     * Enregistre l'activité quotidienne d'un compte authentifié sur les routes API.
     * Au plus une écriture par compte et par jour, avec vérification préalable en cache.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user && ! empty($user->id)) {
            $today = Carbon::now('UTC')->toDateString();
            $cacheKey = "user_daily_activity:{$user->id}:{$today}";

            if (! Cache::has($cacheKey)) {
                try {
                    DB::table('user_daily_activity')->insertOrIgnore([
                        'user_id' => $user->id,
                        'date' => $today,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    Cache::put($cacheKey, true, now()->endOfDay());
                } catch (\Throwable) {
                    // Silencieux : ne jamais bloquer la requête client en cas d'incident
                }
            }
        }

        return $response;
    }
}

