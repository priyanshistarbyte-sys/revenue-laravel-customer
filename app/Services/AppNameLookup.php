<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Resolve a mobile app's display name from its App ID.
 *  - Android package (com.example.app) → Google Play store page title.
 *  - iOS numeric id (123456789 / id123456789) or bundle id → Apple's public lookup API.
 * Misses are cached for a day so a bad / unlisted ID isn't re-fetched on every page load.
 */
class AppNameLookup
{
    public static function find(string $appId): ?string
    {
        $appId = trim($appId);
        if ($appId === '') return null;

        $key = 'app_name_miss:' . md5($appId);
        if (Cache::has($key)) return null;

        if (preg_match('/^(?:id)?(\d{5,})$/i', $appId, $m)) {
            $name = self::apple('id=' . $m[1]);
        } else {
            $name = self::googlePlay($appId) ?? self::apple('bundleId=' . rawurlencode($appId));
        }

        if ($name === null) Cache::put($key, 1, now()->addDay());
        return $name;
    }

    /**
     * App links saved without a name (rows from `links`): fetch each name from the
     * store once, store it, and set it on the row in place.
     */
    public static function fillMissing(\PDO $pdo, array &$links): void
    {
        $save = null;
        foreach ($links as &$lk) {
            if (($lk['link_type'] ?? 'web') !== 'app' || trim((string) ($lk['app_name'] ?? '')) !== '') continue;
            if (trim((string) ($lk['app_id'] ?? '')) === '' || empty($lk['id'])) continue;
            $name = self::find((string) $lk['app_id']);
            if ($name === null) continue;
            $save ??= $pdo->prepare("UPDATE links SET app_name = ? WHERE id = ?");
            $save->execute([$name, $lk['id']]);
            $lk['app_name'] = $name;
        }
        unset($lk);
    }

    private static function googlePlay(string $package): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)+$/', $package)) return null;
        $html = self::get('https://play.google.com/store/apps/details?hl=en&id=' . rawurlencode($package));
        if ($html === null) return null;
        if (!preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $m)) return null;
        $name = trim(preg_replace('/\s*-\s*Apps on Google Play\s*$/i', '', html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)));
        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    private static function apple(string $query): ?string
    {
        $json = self::get('https://itunes.apple.com/lookup?' . $query);
        $data = $json ? json_decode($json, true) : null;
        $name = trim((string) ($data['results'][0]['trackName'] ?? ''));
        return $name !== '' ? mb_substr($name, 0, 255) : null;
    }

    private static function get(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string) $body : null;
    }
}
