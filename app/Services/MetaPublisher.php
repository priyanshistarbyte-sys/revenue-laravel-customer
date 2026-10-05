<?php

namespace App\Services;

/**
 * Publishes a campaign draft to the Meta Marketing API:
 *   Campaign → Ad Set(s) → Ad Creative(s) → Ad(s).
 *
 * Create-or-update: when the draft (or a nested ad set / ad) carries a linked Meta
 * id (_src_campaign_id / _src_adset_id / _src_ad_id — set by the pull or a previous
 * publish) the object is UPDATED in place; otherwise it is CREATED with status
 * self::NEW_STATUS (ACTIVE — goes live as soon as Meta approves). Creatives are
 * immutable on Meta, so a fresh one is always created and the ad is pointed at it.
 *
 * Mock-aware (META_MOCK). publish() returns the draft enriched with the resulting
 * Meta ids so subsequent publishes keep updating the same objects.
 */
class MetaPublisher
{
    /** Status new campaigns/ad sets/ads are created with. ACTIVE = live once Meta approves. */
    private const NEW_STATUS = 'ACTIVE';

    private array $cfg;
    private string $ver;
    private bool $mock;

    public function __construct()
    {
        $this->cfg  = config('adledger.meta');
        $this->ver  = $this->cfg['api_version'] ?? 'v21.0';
        $this->mock = !empty($this->cfg['mock']);
    }

    /**
     * Returns ['ok'=>bool, 'draft'=>enriched draft, 'campaign_id'=>?, 'updated'=>bool, 'error'=>?].
     */
    public function publish(array $draft): array
    {
        try {
            [$act, $token] = $this->resolveAccount($draft);
            $mode = strtoupper(trim((string) ($draft['budget_mode'] ?? '')));
            $cbo  = $mode === 'CBO' ? true : ($mode === 'ABO' ? false : ((float) ($draft['budget'] ?? 0)) > 0);

            $srcCamp = $draft['_src_campaign_id'] ?? ($draft['_meta']['campaign_id'] ?? '');
            $updated = (bool) $srcCamp;
            if ($srcCamp) { $this->updateCampaign($token, $srcCamp, $draft, $cbo); $campaignId = $srcCamp; }
            else          { $campaignId = $this->createCampaign($act, $token, $draft, $cbo); }
            $draft['_src_campaign_id'] = $campaignId;

            foreach (($draft['adsets'] ?? []) as $ai => $adset) {
                $srcAdset = $adset['_src_adset_id'] ?? '';
                if ($srcAdset) { $this->updateAdSet($token, $srcAdset, $draft, $adset, $cbo); $adsetId = $srcAdset; }
                else           { $adsetId = $this->createAdSet($act, $token, $draft, $adset, $campaignId, $cbo); }
                $draft['adsets'][$ai]['_src_adset_id'] = $adsetId;

                foreach (($adset['ads'] ?? []) as $adi => $ad) {
                    $creativeId = $this->createCreative($act, $token, $draft, $ad);   // creatives are immutable → always new
                    $srcAd = $ad['_src_ad_id'] ?? '';
                    if ($srcAd) { $this->updateAd($token, $srcAd, $ad, $creativeId); $adId = $srcAd; }
                    else        { $adId = $this->createAd($act, $token, $ad, $adsetId, $creativeId); }
                    $draft['adsets'][$ai]['ads'][$adi]['_src_ad_id']       = $adId;
                    $draft['adsets'][$ai]['ads'][$adi]['_src_creative_id'] = $creativeId;
                }
            }

            return ['ok' => true, 'draft' => $draft, 'campaign_id' => $campaignId, 'updated' => $updated, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'draft' => $draft, 'campaign_id' => null, 'updated' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Campaign ────────────────────────────────────────────────────────
    private function campaignBase(array $d, bool $cbo): array
    {
        $p = ['name' => $this->str($d['camp_name'] ?? 'Campaign')];
        if ($cbo) {
            $p[$this->budgetField($d['budget_type'] ?? 'DAILY')] = (string) $this->cents($d['budget']);
            $strategy = (string) ($d['camp_bid_strategy'] ?? '');
            $anyBid = (float) ($d['camp_bid_roas'] ?? 0) > 0;
            $anyCpr = false;
            foreach (($d['adsets'] ?? []) as $a) {
                if ((float) ($a['bid_roas'] ?? 0) > 0) $anyBid = true;
                if ((float) ($a['cost_per_result'] ?? 0) > 0) $anyCpr = true;
            }
            if ($this->isCapBid($strategy) && $anyBid) {
                $p['bid_strategy'] = $strategy;                        // cap w/ per-ad-set bid_amount
            } elseif ($strategy === 'LOWEST_COST_WITH_MIN_ROAS' && $anyBid) {
                $p['bid_strategy'] = $strategy;
                $p['bid_constraints'] = json_encode(['roas_average_floor' => (int) round((float) ($d['camp_bid_roas'] ?? 0) * 10000)]);
            } elseif ($anyCpr) {
                $p['bid_strategy'] = 'COST_CAP';                       // cost-per-result goal
            } else {
                $p['bid_strategy'] = 'LOWEST_COST_WITHOUT_CAP';
            }
        }
        return $p;
    }
    private function isCapBid(string $s): bool { return in_array($s, ['LOWEST_COST_WITH_BID_CAP', 'COST_CAP'], true); }

    /** Resolve an ad set's bid strategy + amount. A Cost/result goal maps to COST_CAP;
     *  an explicit cap/ROAS strategy needs its Bid/ROAS amount; else Lowest Cost. */
    private function applyBid(array &$p, string $strategy, float $bidRoas, float $cpr): void
    {
        if ($this->isCapBid($strategy) && $bidRoas > 0) {
            $p['bid_strategy'] = $strategy;
            $p['bid_amount'] = (string) $this->cents($bidRoas);
        } elseif ($strategy === 'LOWEST_COST_WITH_MIN_ROAS' && $bidRoas > 0) {
            $p['bid_strategy'] = $strategy;
            $p['bid_constraints'] = json_encode(['roas_average_floor' => (int) round($bidRoas * 10000)]);
        } elseif ($cpr > 0) {
            $p['bid_strategy'] = 'COST_CAP';
            $p['bid_amount'] = (string) $this->cents($cpr);
        } else {
            $p['bid_strategy'] = 'LOWEST_COST_WITHOUT_CAP';
        }
    }
    private function createCampaign(string $act, string $token, array $d, bool $cbo): string
    {
        $cats = (!empty($d['special_category']) && $d['special_category'] !== 'NONE') ? [$d['special_category']] : [];
        $p = $this->campaignBase($d, $cbo) + [
            'objective'             => $d['objective'] ?? 'OUTCOME_TRAFFIC',
            'status'                => self::NEW_STATUS,
            'buying_type'           => 'AUCTION',
            'special_ad_categories' => json_encode($cats),
            // Meta requires this flag to be explicit, but it can't be true alongside a
            // campaign budget. We use classic campaign budget (CBO) or per-ad-set
            // budgets — never Meta's "ad set budget sharing" — so it's always false.
            'is_adset_budget_sharing_enabled' => 'false',
        ];
        return $this->post($act, $token, 'campaigns', $p, 'camp');
    }
    private function updateCampaign(string $token, string $id, array $d, bool $cbo): void
    {
        $this->update($token, $id, $this->campaignBase($d, $cbo), 'campaign');
    }

    // ── Ad Set ──────────────────────────────────────────────────────────
    private function adsetBase(array $d, array $a, bool $cbo): array
    {
        $p = ['name' => $this->str($a['adset_name'] ?? 'Ad set'), 'targeting' => json_encode($this->targeting($a))];
        if (!$cbo && ((float) ($a['adset_budget'] ?? 0)) > 0) {
            $p[$this->budgetField($d['budget_type'] ?? 'DAILY')] = (string) $this->cents($a['adset_budget']);
            $this->applyBid($p, (string) ($a['bid_strategy'] ?? ''), (float) ($a['bid_roas'] ?? 0), (float) ($a['cost_per_result'] ?? 0));
        }
        // Under CBO the campaign carries the strategy, but a cap / cost-per-result needs
        // the bid_amount on each ad set.
        if ($cbo) {
            $cpr = (float) ($a['cost_per_result'] ?? 0);
            if ($this->isCapBid((string) ($d['camp_bid_strategy'] ?? ''))) {
                $bid = (float) (($a['bid_roas'] ?? '') !== '' ? $a['bid_roas'] : ($d['camp_bid_roas'] ?? 0));
                if ($bid > 0) $p['bid_amount'] = (string) $this->cents($bid);
            } elseif ($cpr > 0) {
                $p['bid_amount'] = (string) $this->cents($cpr);
            }
        }
        if ($t = $this->isoTime($a['start_date'] ?? '', $a['start_time'] ?? '')) $p['start_time'] = $t;
        if ($t = $this->isoTime($a['end_date'] ?? '', $a['end_time'] ?? ''))     $p['end_time']   = $t;
        return $p;
    }
    private function createAdSet(string $act, string $token, array $d, array $a, string $campaignId, bool $cbo): string
    {
        $p = $this->adsetBase($d, $a, $cbo) + [
            'campaign_id'       => $campaignId,
            'status'            => self::NEW_STATUS,
            'billing_event'     => $a['billing'] ?? 'IMPRESSIONS',
            'optimization_goal' => $a['performance_goal'] ?? 'LINK_CLICKS',
        ];
        if (!empty($d['pixel']) && !empty($a['event_type'])) {
            $p['promoted_object'] = json_encode(['pixel_id' => $d['pixel'], 'custom_event_type' => $a['event_type']]);
        }
        return $this->post($act, $token, 'adsets', $p, 'adset');
    }
    private function updateAdSet(string $token, string $id, array $d, array $a, bool $cbo): void
    {
        $this->update($token, $id, $this->adsetBase($d, $a, $cbo), 'ad set');
    }

    private function targeting(array $a): array
    {
        $t = ['geo_locations' => ['countries' => array_values(array_filter(array_map('trim', explode(',', (string) ($a['country'] ?? '')))))]];
        if (($a['age_min'] ?? '') !== '') $t['age_min'] = (int) $a['age_min'];
        if (($a['age_max'] ?? '') !== '') $t['age_max'] = (int) $a['age_max'];
        if (($a['gender'] ?? 'all') === 'male')   $t['genders'] = [1];
        if (($a['gender'] ?? 'all') === 'female') $t['genders'] = [2];
        // Placement preset → publisher_platforms. "all" leaves Meta's default (every
        // placement); the others restrict it (e.g. "facebook" excludes Instagram, which
        // is needed when the ad account has no linked Instagram account).
        $platMap = [
            'fb_ig'         => ['facebook', 'instagram'],
            'facebook'      => ['facebook'],
            'instagram'     => ['instagram'],
            'fb_ig_msg'     => ['facebook', 'instagram', 'messenger'],
            'fb_ig_threads' => ['facebook', 'instagram', 'threads'],
        ];
        $plat = strtolower(trim((string) ($a['platforms'] ?? 'all')));
        if (!empty($platMap[$plat])) $t['publisher_platforms'] = $platMap[$plat];

        // Placement positions per platform (empty = all). Only sent for platforms that
        // are actually active, or Meta rejects positions for an excluded platform.
        $active = !empty($platMap[$plat]) ? $platMap[$plat] : ['facebook', 'instagram', 'audience_network', 'messenger', 'threads'];
        $posMap = [
            'facebook'         => ['fb_positions', 'facebook_positions'],
            'instagram'        => ['ig_positions', 'instagram_positions'],
            'audience_network' => ['an_positions', 'audience_network_positions'],
            'messenger'        => ['msg_positions', 'messenger_positions'],
            'threads'          => ['threads_positions', 'threads_positions'],
        ];
        foreach ($posMap as $platform => [$field, $metaKey]) {
            if (!in_array($platform, $active, true)) continue;
            $vals = array_values(array_filter(array_map('trim', explode(',', (string) ($a[$field] ?? '')))));
            if ($vals) $t[$metaKey] = $vals;
        }

        // Meta now requires the Advantage+ audience flag to be explicit. Driven by the
        // grid's "Advantage+ Audience" checkbox (1 = expand audience, 0 = use as-is).
        $t['targeting_automation'] = ['advantage_audience' => !empty($a['adv_audience']) ? 1 : 0];
        return $t;
    }

    // ── Ad Creative (always created fresh) ──────────────────────────────
    private function createCreative(string $act, string $token, array $d, array $ad): string
    {
        $link  = $this->withParams($ad['dest_url'] ?? '', $ad['url_params'] ?? '');
        $story = ['page_id' => $d['page'] ?? ''];
        $cta   = (!empty($ad['cta']) && $ad['cta'] !== 'NO_BUTTON') ? ['type' => $ad['cta'], 'value' => ['link' => $link]] : null;

        $videoRef = $this->first($ad['video'] ?? '');
        $imageRef = $this->first($ad['image'] ?? '');

        if ($videoRef) {
            $vd = ['video_id' => $videoRef, 'message' => $this->first($ad['message'] ?? '', "\n"),
                   'title' => $this->first($ad['title'] ?? '', "\n"), 'link_description' => $this->first($ad['description'] ?? '', "\n")];
            if ($imageRef) $vd['image_hash'] = $this->imageHash($act, $token, $imageRef);
            if ($cta) $vd['call_to_action'] = $cta;
            $story['video_data'] = $vd;
        } else {
            $ld = ['link' => $link, 'message' => $this->first($ad['message'] ?? '', "\n"),
                   'name' => $this->first($ad['title'] ?? '', "\n"), 'description' => $this->first($ad['description'] ?? '', "\n")];
            if ($imageRef) $ld['image_hash'] = $this->imageHash($act, $token, $imageRef);
            if ($cta) $ld['call_to_action'] = $cta;
            $story['link_data'] = $ld;
        }

        // Give the creative an Instagram identity so Meta doesn't reject it. We use the
        // ad account's own IG if it has one, otherwise the page's Page-Backed Instagram
        // Account (the "use Facebook Page on Instagram" identity Ads Manager creates).
        // With Facebook-only placements no Instagram ad actually runs.
        $igId = $this->instagramActorId($act, $token, (string) ($d['page'] ?? ''));
        if ($igId !== '') $story['instagram_actor_id'] = $igId;

        return $this->post($act, $token, 'adcreatives', [
            'name'              => $this->str(($ad['ad_name'] ?? 'Ad') . ' creative'),
            'object_story_spec' => json_encode($story),
        ], 'creative');
    }

    /**
     * An Instagram actor id usable by this ad account: its own connected IG if any,
     * else the page's Page-Backed Instagram Account (get-or-create) — which represents
     * the Facebook Page on Instagram, exactly like Ads Manager's "use Facebook Page".
     * Cached per account+page; best-effort — '' if none can be obtained.
     */
    private array $igCache = [];
    private function instagramActorId(string $act, string $token, string $pageId): string
    {
        if ($this->mock) return '';
        $key = $act . '|' . $pageId;
        if (array_key_exists($key, $this->igCache)) return $this->igCache[$key];

        // 1. An Instagram account connected to the ad account.
        $r = $this->httpGet("https://graph.facebook.com/{$this->ver}/{$act}/instagram_accounts?fields=id&limit=1", $token);
        $id = (string) ($r['data'][0]['id'] ?? '');

        // 2. The page's Page-Backed Instagram Account — reuse if present, else create it.
        if ($id === '' && $pageId !== '') {
            $g = $this->httpGet("https://graph.facebook.com/{$this->ver}/{$pageId}/page_backed_instagram_accounts?fields=id", $token);
            $id = (string) ($g['data'][0]['id'] ?? '');
            if ($id === '') {
                try {
                    $c = $this->http("https://graph.facebook.com/{$this->ver}/{$pageId}/page_backed_instagram_accounts", $token, []);
                    $id = (string) ($c['id'] ?? '');
                } catch (\Throwable $e) { /* token can't manage the page's PBIA */ }
            }
        }
        return $this->igCache[$key] = $id;
    }

    /** Best-effort GET (returns [] on any error) — used for optional lookups. */
    private function httpGet(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code < 200 || $code >= 300) return [];
        return json_decode($res, true) ?: [];
    }

    // ── Ad ──────────────────────────────────────────────────────────────
    private function createAd(string $act, string $token, array $ad, string $adsetId, string $creativeId): string
    {
        return $this->post($act, $token, 'ads', [
            'name'     => $this->str($ad['ad_name'] ?? 'Ad'),
            'adset_id' => $adsetId,
            'creative' => json_encode(['creative_id' => $creativeId]),
            'status'   => self::NEW_STATUS,
        ], 'ad');
    }
    private function updateAd(string $token, string $id, array $ad, string $creativeId): void
    {
        $this->update($token, $id, [
            'name'     => $this->str($ad['ad_name'] ?? 'Ad'),
            'creative' => json_encode(['creative_id' => $creativeId]),
        ], 'ad');
    }

    /**
     * An image_hash valid for THIS ad account. Image hashes are per-ad-account, so a
     * synced image from another account (or a local upload) must be uploaded to this
     * account first. Cached per account+ref so repeated ads don't re-upload.
     */
    private array $imgCache = [];
    private function imageHash(string $act, string $token, string $ref): string
    {
        if ($ref === '') return '';
        $ck = $act . '|' . $ref;
        if (isset($this->imgCache[$ck])) return $this->imgCache[$ck];
        if ($this->mock) return $this->imgCache[$ck] = 'mockhash_' . substr(md5($ref), 0, 12);

        $row = getDB()->prepare("SELECT url, source, act_id FROM meta_media WHERE media_ref = ? LIMIT 1");
        $row->execute([$ref]);
        $m = $row->fetch(\PDO::FETCH_ASSOC) ?: [];
        $src  = (string) ($m['source'] ?? '');
        $mAct = (string) ($m['act_id'] ?? '');
        if ($mAct !== '' && strncmp($mAct, 'act_', 4) !== 0) $mAct = 'act_' . $mAct;

        // Synced image already belonging to this ad account → the ref is its hash.
        if ($src === 'meta' && $mAct === $act) return $this->imgCache[$ck] = $ref;

        // Otherwise upload the actual image bytes to this ad account for a valid hash.
        $bytes = $this->imageBytes((string) ($m['url'] ?? ''));
        if ($bytes === '') {
            if ($src === '') return $this->imgCache[$ck] = $ref;        // unknown ref — assume it's already a hash
            throw new \Exception("image source unavailable for {$ref}");
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mcimg');
        file_put_contents($tmp, $bytes);
        try {
            $res = $this->http("https://graph.facebook.com/{$this->ver}/{$act}/adimages", $token, ['filename' => new \CURLFile($tmp)]);
        } finally { @unlink($tmp); }
        $first = is_array($res['images'] ?? null) ? reset($res['images']) : [];
        if (empty($first['hash'])) throw new \Exception('image upload to ad account returned no hash');
        return $this->imgCache[$ck] = (string) $first['hash'];
    }

    /** Read an image's bytes from a local /storage path or a remote URL. */
    private function imageBytes(string $url): string
    {
        if ($url === '') return '';
        $path = parse_url($url, PHP_URL_PATH);
        if ($path) { $local = public_path($path); if (is_file($local)) return (string) @file_get_contents($local); }

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($data !== false && $code >= 200 && $code < 300) ? (string) $data : '';
    }

    // ── HTTP + helpers ──────────────────────────────────────────────────
    private function post(string $act, string $token, string $edge, array $params, string $kind): string
    {
        if ($this->mock) return 'mock_' . $kind . '_' . substr(md5($act . $edge . json_encode($params) . microtime()), 0, 12);
        try {
            $res = $this->http("https://graph.facebook.com/{$this->ver}/{$act}/{$edge}", $token, $params);
        } catch (\Throwable $e) {
            throw new \Exception("[{$kind} create] " . $e->getMessage());
        }
        if (empty($res['id'])) throw new \Exception(ucfirst($kind) . ' create returned no id');
        return (string) $res['id'];
    }

    /**
     * Flip a live campaign's delivery status (ACTIVE ⇄ PAUSED) on Meta.
     * Returns ['ok'=>bool, 'status'=>?, 'error'=>?].
     */
    public function setStatus(string $account, string $campaignId, string $status): array
    {
        $status = strtoupper(trim($status)) === 'ACTIVE' ? 'ACTIVE' : 'PAUSED';
        try {
            if (trim($campaignId) === '') throw new \Exception('no campaign id');
            [$act, $token] = $this->resolveAccount(['account' => $account]);
            $this->update($token, $campaignId, ['status' => $status], 'campaign');
            return ['ok' => true, 'status' => $status];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** POST to an object node to update it in place. */
    private function update(string $token, string $id, array $params, string $kind = 'object'): void
    {
        if ($this->mock) return;
        try {
            $this->http("https://graph.facebook.com/{$this->ver}/{$id}", $token, $params);
        } catch (\Throwable $e) {
            throw new \Exception("[{$kind} update] " . $e->getMessage());
        }
    }

    private function http(string $url, string $token, array $post): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP POST failed: $err"); }
        curl_close($ch);
        $j = json_decode($res, true) ?: [];
        if ($code < 200 || $code >= 300) {
            throw new \Exception($j['error']['error_user_title'] ?? $j['error']['message'] ?? "HTTP $code");
        }
        return $j;
    }

    /** act_id + api token for the draft's ad account. */
    private function resolveAccount(array $d): array
    {
        $act = (string) ($d['account'] ?? '');
        if ($act === '') throw new \Exception('no ad account selected');
        $act = str_starts_with($act, 'act_') ? $act : ('act_' . $act);

        $stmt = getDB()->prepare(
            "SELECT ma.api_token FROM meta_ad_accounts aa
             JOIN meta_accounts ma ON ma.id = aa.meta_account_id
             WHERE aa.act_id = ? LIMIT 1"
        );
        $stmt->execute([$act]);
        $token = (string) $stmt->fetchColumn();
        if ($token === '' && !$this->mock) throw new \Exception('no API token for this ad account');
        return [$act, $token];
    }

    private function budgetField(string $type): string { return $type === 'LIFETIME' ? 'lifetime_budget' : 'daily_budget'; }
    private function cents($v): int { return (int) round(((float) $v) * 100); }
    private function str($v): string { return trim((string) $v); }
    private function first($v, string $sep = ','): string { $p = explode($sep, (string) $v); return trim($p[0] ?? ''); }

    private function withParams(string $link, string $params): string
    {
        $params = trim($params);
        if ($link === '' || $params === '') return $link;
        return $link . (strpos($link, '?') === false ? '?' : '&') . ltrim($params, '?&');
    }

    private function isoTime(string $date, string $time): string
    {
        $date = trim($date); if ($date === '') return '';
        $time = trim($time) ?: '00:00';
        return date('c', strtotime("$date $time"));
    }
}
