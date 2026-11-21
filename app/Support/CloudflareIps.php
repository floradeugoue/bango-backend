<?php

namespace App\Support;

class CloudflareIps
{
    /**
     * Plages IPv4 officielles de Cloudflare (publiées sur https://www.cloudflare.com/ips-v4).
     */
    public const IPV4 = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ];

    /**
     * Plages IPv6 officielles de Cloudflare (publiées sur https://www.cloudflare.com/ips-v6).
     */
    public const IPV6 = [
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * Réseaux locaux et sous-réseaux privés (RFC 1918) pour reverse proxies internes / load balancers.
     */
    public const PRIVATE_NETWORKS = [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];

    /**
     * Retourne l'ensemble des plages d'adresses IP de confiance (Cloudflare + réseaux privés / locaux).
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        $customProxies = env('TRUSTED_PROXIES');
        if ($customProxies === '*' || $customProxies === '**') {
            return ['*'];
        }

        $all = array_merge(self::IPV4, self::IPV6, self::PRIVATE_NETWORKS);

        if ($customProxies) {
            $extra = array_filter(array_map('trim', explode(',', $customProxies)));
            $all = array_values(array_unique(array_merge($all, $extra)));
        }

        return $all;
    }
}
