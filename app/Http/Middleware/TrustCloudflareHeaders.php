<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrustCloudflareHeaders
{
    /**
     * Handle an incoming request.
     *
     * Si la requête provient d'un proxy de confiance (Cloudflare ou proxy intermédiaire)
     * et qu'un en-tête CF-Connecting-IP est transmis, garantit que l'adresse IP du client
     * réel est priorisée dans X-Forwarded-For pour que $request->ip() reflète l'IP de l'opérateur.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isFromTrustedProxy()) {
            $cfIp = trim((string) ($request->header('cf-connecting-ip') ?? $request->server->get('HTTP_CF_CONNECTING_IP') ?? ''));

            if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
                $currentXff = $request->header('x-forwarded-for');

                if (! $currentXff) {
                    $request->headers->set('x-forwarded-for', $cfIp);
                    $request->server->set('HTTP_X_FORWARDED_FOR', $cfIp);
                } else {
                    $ips = array_map('trim', explode(',', $currentXff));
                    if (! in_array($cfIp, $ips, true)) {
                        $newXff = $cfIp.', '.$currentXff;
                        $request->headers->set('x-forwarded-for', $newXff);
                        $request->server->set('HTTP_X_FORWARDED_FOR', $newXff);
                    }
                }
            }
        }

        return $next($request);
    }
}
