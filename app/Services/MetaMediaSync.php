<?php

namespace App\Services;

/**
 * Syncs Creative-Hub media — ad images and ad videos — from the Graph API into
 * meta_media (source='meta'), so the campaign builder's Video/Image pickers show
 * the account's real assets. Pure cURL, mirrors MetaAssetsSync. Mock-aware.
 */
class MetaMediaSync
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('adledger.meta');
    }

    /** Sync media for every sync-ready Meta account (or one, via $onlyAccount). */
    public function run(int $onlyAccount = 0): array
    {
        $pdo  = getDB();
        $mock = !empty($this->cfg['mock']);
        $sql  = "SELECT * FROM meta_accounts WHERE active = 1 AND api_token <> ''"
              . ($onlyAccount ? " AND id = " . (int) $onlyAccount : "") . " ORDER BY name";
        $accounts = $pdo->query($sql)->fetchAll();

        if (!$accounts) {
            return ['ok' => false, 'msg' => 'No sync-ready Meta accounts (need an API token).'];
        }

        $ins = $pdo->prepare(
            "INSERT INTO meta_media (kind, media_ref, name, url, size_bytes, source, meta_account_id, act_id, created_at, updated_at)
             VALUES (?,?,?,?,?, 'meta', ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE name=VALUES(name), url=VALUES(url), size_bytes=VALUES(size_bytes),
                                     meta_account_id=VALUES(meta_account_id), act_id=VALUES(act_id), updated_at=NOW()"
        );

        $summary = []; $anyOk = false; $imgN = 0; $vidN = 0;

        foreach ($accounts as $acc) {
            try {
                $acts = $pdo->prepare("SELECT act_id FROM meta_ad_accounts WHERE meta_account_id = ?");
                $acts->execute([$acc['id']]);
                $actIds = $acts->fetchAll(\PDO::FETCH_COLUMN)
                    ?: array_filter(array_map('trim', explode(',', (string) $acc['ad_account_ids'])));

                foreach ($actIds as $act) {
                    $act = str_starts_with((string) $act, 'act_') ? $act : ('act_' . $act);

                    foreach ($this->images($acc, $act, $mock) as $m) {
                        if (empty($m['ref'])) continue;
                        $ins->execute(['image', $m['ref'], $m['name'], $m['url'], $m['size'], $acc['id'], $act]);
                        $imgN++;
                    }
                    foreach ($this->videos($acc, $act, $mock) as $m) {
                        if (empty($m['ref'])) continue;
                        $ins->execute(['video', $m['ref'], $m['name'], $m['url'], $m['size'], $acc['id'], $act]);
                        $vidN++;
                    }
                }
                $anyOk = true;
                $summary[] = "{$acc['name']}: ok";
            } catch (\Throwable $e) {
                $summary[] = "{$acc['name']}: ERROR " . $e->getMessage();
            }
        }

        return ['ok' => $anyOk, 'msg' => 'Meta media sync' . ($mock ? ' [MOCK]' : '')
            . " — {$imgN} images, {$vidN} videos | " . implode(' | ', $summary)];
    }

    private function images(array $acc, string $act, bool $mock): array
    {
        $rows = $mock ? [
            ['hash' => 'imgmock1', 'name' => 'promo-a.jpg', 'url' => 'https://via.placeholder.com/120'],
            ['hash' => 'imgmock2', 'name' => 'promo-b.jpg', 'url' => 'https://via.placeholder.com/120'],
        ] : $this->fetch($acc, "/{$act}/adimages?fields=hash,name,url,permalink_url&limit=200");

        return array_map(fn ($r) => [
            'ref'  => (string) ($r['hash'] ?? ''),
            'name' => $this->clip($r['name'] ?? ($r['hash'] ?? '')),
            'url'  => (string) ($r['url'] ?? ($r['permalink_url'] ?? '')),
            'size' => null,
        ], $rows);
    }

    private function videos(array $acc, string $act, bool $mock): array
    {
        $rows = $mock ? [
            ['id' => 'vidmock1', 'title' => 'brand-spot.mp4'],
        ] : $this->fetch($acc, "/{$act}/advideos?fields=id,title,thumbnails&limit=200");

        return array_map(function ($r) {
            $thumb = $r['thumbnails']['data'][0]['uri'] ?? '';
            return [
                'ref'  => (string) ($r['id'] ?? ''),
                'name' => $this->clip($r['title'] ?? ($r['id'] ?? '')),
                'url'  => (string) $thumb,
                'size' => null,
            ];
        }, $rows);
    }

    /** Keep names within the column width (Meta image names / video titles run long). */
    private function clip($s): string
    {
        return mb_substr((string) $s, 0, 500);
    }

    /** GET a Graph endpoint (following paging) and return the merged `data` rows. */
    private function fetch(array $acc, string $path): array
    {
        $token = trim((string) ($acc['api_token'] ?? ''));
        if ($token === '') throw new \Exception('no API token');
        $ver = $this->cfg['api_version'] ?? 'v21.0';
        $url = "https://graph.facebook.com/$ver" . $path;

        $out = []; $guard = 0;
        while ($url && $guard++ < 50) {
            $page = $this->http($url, $token);
            foreach (($page['data'] ?? []) as $d) $out[] = $d;
            $url = $page['paging']['next'] ?? '';
        }
        return $out;
    }

    private function http(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP GET failed: $err"); }
        curl_close($ch);
        $j = json_decode($res, true) ?: [];
        if ($code < 200 || $code >= 300) {
            throw new \Exception("HTTP $code: " . ($j['error']['message'] ?? $res));
        }
        return $j;
    }
}
