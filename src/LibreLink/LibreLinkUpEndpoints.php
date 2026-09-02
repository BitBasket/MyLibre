<?php

declare(strict_types=1);

namespace App\LibreLink;

final class LibreLinkUpEndpoints
{
    public const GLOBAL_BASE_URI = 'https://api.libreview.io/';
    public const LOGIN = 'llu/auth/login';
    public const CONNECTIONS = 'llu/connections';
    public const PRODUCT = 'llu.android';

    /** @var array<string, string> */
    public const REGIONAL_HOSTS = [
        'AE' => 'api-ae.libreview.io',
        'AP' => 'api-ap.libreview.io',
        'AU' => 'api-au.libreview.io',
        'CA' => 'api-ca.libreview.io',
        'CN' => 'api-cn.myfreestyle.cn',
        'DE' => 'api-de.libreview.io',
        'EU' => 'api-eu.libreview.io',
        'EU2' => 'api-eu2.libreview.io',
        'FR' => 'api-fr.libreview.io',
        'JP' => 'api-jp.libreview.io',
        'LA' => 'api-la.libreview.io',
        'RU' => 'api.libreview.ru',
        'US' => 'api-us.libreview.io',
    ];

    public static function normalizeBaseUri(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return self::GLOBAL_BASE_URI;
        }

        if (!str_contains($uri, '://')) {
            $uri = 'https://' . $uri;
        }

        return rtrim($uri, '/') . '/';
    }

    public static function baseUriForRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        if ($region === '' || $region === 'AUTO' || $region === 'GLOBAL') {
            return self::GLOBAL_BASE_URI;
        }

        $host = self::REGIONAL_HOSTS[$region] ?? ('api-' . strtolower($region) . '.libreview.io');

        return self::normalizeBaseUri('https://' . $host);
    }

    public static function graph(string $patientId): string
    {
        return 'llu/connections/' . rawurlencode($patientId) . '/graph';
    }

    /**
     * @return array<string, string>
     */
    public static function clientHeaders(string $clientVersion): array
    {
        return [
            'product' => self::PRODUCT,
            'version' => $clientVersion,
            'User-Agent' => 'LibreLinkUp/' . $clientVersion,
            'cache-control' => 'no-cache',
            'pragma' => 'no-cache',
        ];
    }
}
