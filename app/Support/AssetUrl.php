<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Builds absolute public URLs for files stored on the public disk.
 *
 * Render and other hosts often cache APP_URL as localhost during build; this helper
 * prefers ASSET_URL / APP_URL when set, otherwise the incoming request host.
 */
class AssetUrl
{
    public static function fromStoragePath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return self::rewriteLocalHost(self::ensureProductionHttps($path));
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');
        $base = self::baseUrl();
        if ($base === '') {
            return Storage::disk('public')->url($relative);
        }

        return self::rewriteLocalHost($base.'/storage/'.$relative);
    }

    private static function baseUrl(): string
    {
        $asset = rtrim((string) config('services.pharmezel.asset_url', ''), '/');
        if ($asset !== '' && ! self::isLocalHost($asset)) {
            return self::ensureProductionHttps($asset);
        }

        $app = rtrim((string) config('app.url', ''), '/');
        if ($app !== '' && ! self::isLocalHost($app)) {
            return self::ensureProductionHttps($app);
        }

        try {
            $host = rtrim(request()->getSchemeAndHttpHost(), '/');
            if ($host !== '' && ! self::isLocalHost($host)) {
                return self::ensureProductionHttps($host);
            }
        } catch (\Throwable) {
            // CLI / early boot
        }

        return '';
    }

    private static function rewriteLocalHost(string $url): string
    {
        if (! self::isLocalHost($url)) {
            return $url;
        }

        $base = self::baseUrl();
        if ($base === '') {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $query = parse_url($url, PHP_URL_QUERY);
        $suffix = $path.($query ? '?'.$query : '');

        return rtrim($base, '/').$suffix;
    }

    private static function isLocalHost(string $url): bool
    {
        return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?(/|$)#i', $url);
    }

    private static function ensureProductionHttps(string $url): string
    {
        if (str_starts_with($url, 'http://') && ! app()->environment('local')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }
}
