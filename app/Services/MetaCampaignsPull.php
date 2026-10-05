<?php

namespace App\Services;

/**
 * Pulls campaigns of every status (active, paused, in review, with issues, …) —
 * with their ad sets, ads and creatives — from the Meta Marketing API and
 * reverse-maps them into the builder's draft shape so they can be imported as
 * editable rows — the inverse of MetaPublisher. Each is marked published+linked
 * carrying its live status so the LIVE toggle works right after the pull. Pure
 * cURL, mock-aware. pull() returns ['ok'=>bool, 'campaigns'=>[...], 'msg'=>..].
 */
class MetaCampaignsPull
{
    private array $cfg;
    private string $ver;
    private bool $mock;

    public function __construct()
    {
        $this->cfg  = config('adledger.meta');
        $this->ver  = $this->cfg['api_version'] ?? 'v21.0';
        $this->mock = !empty($this->cfg['mock']);
    }

    public function pull(int $onlyAccount = 0, array $onlyActs = [], int $perAccount = 25): array
    {
        $pdo = getDB();
        // Normalise the requested act ids (prefix act_) into a lookup set.
        $only = [];
        foreach ($onlyActs as $x) {
            $x = trim((string) $x); if ($x === '') continue;
            $only[str_starts_with($x, 'act_') ? $x : ('act_' . $x)] = true;
        }

        $sql = "SELECT * FROM meta_accounts WHERE active = 1 AND api_token <> ''"
             . ($onlyAccount ? " AND id = " . (int) $onlyAccount : "") . " ORDER BY name";
        $accounts = $pdo->query($sql)->fetchAll();
        if (!$accounts) return ['ok' => false, 'campaigns' => [], 'msg' => 'No sync-ready Meta accounts (need an API token).'];

        $out = []; $errs = []; $pagesOut = [];
        foreach ($accounts as $acc) {
            try {
                $acts = $pdo->prepare("SELECT act_id FROM meta_ad_accounts WHERE meta_account_id = ?");
                $acts->execute([$acc['id']]);
                $actIds = $acts->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                $found = [];   // page_id => act_id it was discovered under (for per-account scoping)
                foreach ($actIds as $act) {
                    $act = str_starts_with((string) $act, 'act_') ? $act : ('act_' . $act);
                    if ($only && empty($only[$act])) continue;
                    foreach ($this->fetchCampaigns($acc, $act, $perAccount) as $camp) {
                        $m = $this->mapCampaign($camp, $act);
                        if (!empty($m['page'])) $found[(string) $m['page']] = $act;
                        $out[] = $m;
                    }
                }
                // Persist any pages we discovered so the Fan Page dropdown is populated
                // even when the token can't list pages via /me/accounts or businesses.
                if ($found) {
                    foreach ($this->persistPages($pdo, $acc, $found) as $p) $pagesOut[] = $p;
                }
            } catch (\Throwable $e) {
                $errs[] = $acc['name'] . ': ' . $e->getMessage();
            }
        }

        $msg = 'Pulled ' . count($out) . ' campaign(s)' . ($this->mock ? ' [MOCK]' : '')
             . ($errs ? ' | ' . implode(' | ', $errs) : '') . '.';
        return ['ok' => (!empty($out) || empty($errs)), 'campaigns' => $out, 'pages' => $pagesOut, 'msg' => $msg];
    }

    private function fetchCampaigns(array $acc, string $act, int $limit): array
    {
        if ($this->mock) return $this->mockCampaigns();

        $fields = 'id,name,status,effective_status,issues_info,objective,special_ad_categories,daily_budget,lifetime_budget,bid_strategy,'
            . 'adsets.limit(50){id,name,daily_budget,lifetime_budget,billing_event,optimization_goal,bid_strategy,targeting,start_time,end_time,promoted_object,issues_info,'
            . 'ads.limit(50){id,name,issues_info,ad_review_feedback,creative{id,actor_id,effective_object_story_id,object_story_id,body,title,image_hash,call_to_action_type,link_url,object_story_spec{page_id,link_data,video_data}}}}';
        // No effective_status filter → pull every campaign (active, paused, in review,
        // with issues, …). Meta already excludes deleted/archived from this edge.
        return $this->fetchAll($acc, "/{$act}/campaigns?fields={$fields}&limit={$limit}");
    }

    // ── Reverse mapping ─────────────────────────────────────────────────
    private function mapCampaign(array $c, string $act): array
    {
        $adsets = []; $page = '';
        foreach (($c['adsets']['data'] ?? []) as $as) {
            $A = $this->mapAdset($as);
            foreach ($A['ads'] as &$ad) {
                if ($page === '' && !empty($ad['_page'])) $page = $ad['_page'];
                unset($ad['_page']);
            }
            unset($ad);
            $adsets[] = $A;
        }
        if (!$adsets) $adsets = [['adset_name' => 'AdSet 1', 'ads' => [['ad_name' => 'Ad 1']]]];

        $lifetime = isset($c['lifetime_budget']) && !isset($c['daily_budget']);
        $budget   = $this->dollars($c['daily_budget'] ?? ($c['lifetime_budget'] ?? 0));

        // Configured status drives the LIVE toggle (ACTIVE ⇄ PAUSED); effective_status
        // carries delivery problems (WITH_ISSUES, DISAPPROVED, PENDING_REVIEW, …).
        $configured = strtoupper((string) ($c['status'] ?? 'PAUSED')) === 'ACTIVE' ? 'ACTIVE' : 'PAUSED';
        $effective  = strtoupper((string) ($c['effective_status'] ?? ''));
        $campId     = (string) ($c['id'] ?? '');
        $issues     = $this->collectIssues($c);

        return array_filter([
            'account'           => $act,
            'camp_name'         => (string) ($c['name'] ?? ''),
            'objective'         => (string) ($c['objective'] ?? 'OUTCOME_TRAFFIC'),
            'special_category'  => $this->cat($c['special_ad_categories'] ?? []),
            'budget'            => $budget > 0 ? (string) $budget : '',
            'budget_type'       => $lifetime ? 'LIFETIME' : 'DAILY',
            'camp_bid_strategy' => (string) ($c['bid_strategy'] ?? ''),
            'page'              => $page,
            'adsets'            => $adsets,
            '_imported'         => true,
            '_src_campaign_id'  => $campId,
            // Live Meta campaigns: surface them as published so the LIVE toggle shows
            // and reflects their real state right after the pull.
            '_status'           => 'published',
            '_meta'             => array_filter([
                'campaign_id'      => $campId,
                'updated'          => true,
                'status'           => $configured,
                'effective_status' => $effective,
                'issues'           => $issues,
            ], fn ($v) => $v !== '' && $v !== null && $v !== []),
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Human-readable issue reasons gathered from the campaign, its ad sets and ads
     * (issues_info summaries + per-ad ad_review_feedback). Deduped, capped at 6.
     */
    private function collectIssues(array $c): array
    {
        $out = [];
        $add = function ($items) use (&$out) {
            foreach ((array) $items as $i) {
                if (!is_array($i)) continue;
                $s = trim((string) ($i['error_summary'] ?? '')) ?: trim((string) ($i['error_message'] ?? ''));
                if ($s !== '') $out[] = $s;
            }
        };
        $add($c['issues_info'] ?? []);
        foreach (($c['adsets']['data'] ?? []) as $as) {
            $add($as['issues_info'] ?? []);
            foreach (($as['ads']['data'] ?? []) as $ad) {
                $add($ad['issues_info'] ?? []);
                $out = array_merge($out, $this->reviewFeedback($ad['ad_review_feedback'] ?? []));
            }
        }
        $out = array_values(array_filter(array_unique(array_map('trim', $out))));
        return array_slice($out, 0, 6);
    }

    /** Flatten ad_review_feedback ({global/placement:{policy:msg}}) into message strings. */
    private function reviewFeedback($fb): array
    {
        $out = [];
        $walk = function ($node) use (&$walk, &$out) {
            foreach ((array) $node as $v) {
                if (is_string($v)) { $v = trim($v); if ($v !== '') $out[] = $v; }
                elseif (is_array($v)) $walk($v);
            }
        };
        if (is_array($fb)) $walk($fb);
        return $out;
    }

    private function mapAdset(array $as): array
    {
        $t   = $as['targeting'] ?? [];
        $geo = $t['geo_locations']['countries'] ?? [];
        $g   = $t['genders'] ?? [];
        $po  = $as['promoted_object'] ?? [];
        [$sd, $st] = $this->splitTime($as['start_time'] ?? '');
        [$ed, $et] = $this->splitTime($as['end_time'] ?? '');

        $ads = [];
        foreach (($as['ads']['data'] ?? []) as $ad) $ads[] = $this->mapAd($ad);
        if (!$ads) $ads = [['ad_name' => 'Ad 1']];

        $lifetime = isset($as['lifetime_budget']) && !isset($as['daily_budget']);
        $budget   = $this->dollars($as['daily_budget'] ?? ($as['lifetime_budget'] ?? 0));
        $gender   = (in_array(1, $g) && !in_array(2, $g)) ? 'male' : ((in_array(2, $g) && !in_array(1, $g)) ? 'female' : '');

        return array_filter([
            '_src_adset_id'    => (string) ($as['id'] ?? ''),
            'adset_name'       => (string) ($as['name'] ?? ''),
            'adset_budget'     => $budget > 0 ? (string) $budget : '',
            'billing'          => (string) ($as['billing_event'] ?? ''),
            'performance_goal' => (string) ($as['optimization_goal'] ?? ''),
            'bid_strategy'     => (string) ($as['bid_strategy'] ?? ''),
            'country'          => implode(',', $geo),
            'age_min'          => isset($t['age_min']) ? (string) $t['age_min'] : '',
            'age_max'          => isset($t['age_max']) ? (string) $t['age_max'] : '',
            'gender'           => $gender,
            'pixel'            => (string) ($po['pixel_id'] ?? ''),
            'event_type'       => (string) ($po['custom_event_type'] ?? ''),
            'start_date'       => $sd, 'start_time' => $st, 'end_date' => $ed, 'end_time' => $et,
            'ads'              => $ads,
        ], fn ($v) => $v !== '' && $v !== null && $v !== []);
    }

    private function mapAd(array $ad): array
    {
        $cr   = $ad['creative'] ?? [];
        $oss  = $cr['object_story_spec'] ?? [];
        $link = $oss['link_data'] ?? [];
        $vid  = $oss['video_data'] ?? [];
        $cta  = $link['call_to_action']['type'] ?? ($vid['call_to_action']['type'] ?? ($cr['call_to_action_type'] ?? ''));
        $dest = $link['link'] ?? ($link['call_to_action']['value']['link'] ?? ($cr['link_url'] ?? ''));

        // Page id lives in different spots depending on how the ad was built:
        //   - inline creatives → object_story_spec.page_id
        //   - post-based ads    → <PAGE>_<POST> story ids
        //   - otherwise         → the creative's actor_id (the page acting as advertiser)
        $page = (string) ($oss['page_id'] ?? '');
        if ($page === '' && !empty($cr['effective_object_story_id'])) $page = explode('_', (string) $cr['effective_object_story_id'])[0];
        if ($page === '' && !empty($cr['object_story_id']))           $page = explode('_', (string) $cr['object_story_id'])[0];
        if ($page === '' && !empty($cr['actor_id']))                  $page = (string) $cr['actor_id'];

        return array_filter([
            '_src_ad_id'       => (string) ($ad['id'] ?? ''),
            '_src_creative_id' => (string) ($cr['id'] ?? ''),
            'ad_name'     => (string) ($ad['name'] ?? ''),
            'dest_url'    => (string) $dest,
            'message'     => (string) ($link['message'] ?? ($vid['message'] ?? ($cr['body'] ?? ''))),
            'title'       => (string) ($link['name'] ?? ($vid['title'] ?? ($cr['title'] ?? ''))),
            'description' => (string) ($link['description'] ?? ($vid['link_description'] ?? '')),
            'cta'         => (string) $cta,
            'image'       => (string) ($link['image_hash'] ?? ($cr['image_hash'] ?? '')),
            'video'       => (string) ($vid['video_id'] ?? ''),
            '_page'       => $page,
        ], fn ($v) => $v !== '' && $v !== null);
    }

    /**
     * Upsert the page ids discovered in pulled campaigns into meta_pages, tagged with
     * the ad account (act_id) they were found under so the Fan Page picker can show
     * only that account's pages. $pageActs maps page_id => act_id. Names looked up
     * best-effort. Returns [{id, name, ma, act}] for the client to merge.
     */
    private function persistPages(\PDO $pdo, array $acc, array $pageActs): array
    {
        // Normalise page_id => act_id (array keys of numeric page ids arrive as ints).
        $map = [];
        foreach ($pageActs as $id => $act) {
            $id = trim((string) $id);
            if ($id !== '') $map[$id] = (string) $act;
        }
        if (!$map) return [];
        $names = $this->mock ? [] : $this->fetchNames($acc, array_keys($map));

        $ins = $pdo->prepare("INSERT INTO meta_pages (meta_account_id, act_id, page_id, name, created_at, updated_at)
                              VALUES (?,?,?,?,NOW(),NOW())
                              ON DUPLICATE KEY UPDATE name = IF(VALUES(name) <> '', VALUES(name), name), updated_at = NOW()");
        $out = [];
        foreach ($map as $id => $act) {
            $name = mb_substr((string) ($names[$id] ?? ''), 0, 500);
            $ins->execute([$acc['id'], $act, $id, $name]);
            $out[] = ['id' => $id, 'name' => $name !== '' ? $name : $id, 'ma' => (int) $acc['id'], 'act' => $act];
        }
        return $out;
    }

    /** Batch-lookup page names via Graph `?ids=…&fields=name` (50 at a time). id => name. */
    private function fetchNames(array $acc, array $ids): array
    {
        $token = trim((string) ($acc['api_token'] ?? ''));
        if ($token === '') return [];
        $names = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            try {
                $url = "https://graph.facebook.com/{$this->ver}/?ids=" . rawurlencode(implode(',', $chunk)) . '&fields=name';
                foreach ($this->http($url, $token) as $id => $obj) {
                    if (is_array($obj) && isset($obj['name'])) $names[(string) $id] = (string) $obj['name'];
                }
            } catch (\Throwable $e) {}
        }
        return $names;
    }

    // ── Helpers ─────────────────────────────────────────────────────────
    private function dollars($cents): float { return round(((float) $cents) / 100, 2); }

    private function cat(array $cats): string
    {
        $cats = array_values(array_filter($cats, fn ($c) => $c && $c !== 'NONE'));
        return $cats[0] ?? 'NONE';
    }

    private function splitTime(string $iso): array
    {
        $iso = trim($iso);
        if ($iso === '') return ['', ''];
        $ts = strtotime($iso);
        return $ts ? [date('Y-m-d', $ts), date('H:i', $ts)] : ['', ''];
    }

    private function fetchAll(array $acc, string $path): array
    {
        $token = trim((string) ($acc['api_token'] ?? ''));
        if ($token === '') throw new \Exception('no API token');
        $url = "https://graph.facebook.com/{$this->ver}" . $path;

        $out = []; $guard = 0;
        while ($url && $guard++ < 40) {
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
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new \Exception("HTTP GET failed: $err"); }
        curl_close($ch);
        $j = json_decode($res, true) ?: [];
        if ($code < 200 || $code >= 300) throw new \Exception($j['error']['message'] ?? "HTTP $code");
        return $j;
    }

    private function mockCampaigns(): array
    {
        return [
            $this->mockCampaign('120210000000012345', '[Pulled] Summer Sale', 'ACTIVE', 'ACTIVE'),
            $this->mockCampaign('120210000000054321', '[Pulled] Winter Clearance', 'PAUSED', 'CAMPAIGN_PAUSED'),
            $this->mockCampaign('120210000000099999', '[Pulled] Spring Launch', 'ACTIVE', 'WITH_ISSUES', [
                'Ad disapproved: landing page violates the Personal Health & Appearance policy',
                'Circumventing systems: text-heavy image',
            ]),
        ];
    }

    private function mockCampaign(string $id, string $name, string $status, string $eff, array $issues = []): array
    {
        $reviewFb = $issues ? ['global' => array_combine(
            array_map(fn ($n) => 'POLICY_' . $n, array_keys($issues)),
            $issues
        )] : null;

        return [
            'id' => $id, 'name' => $name, 'status' => $status, 'effective_status' => $eff,
            'objective' => 'OUTCOME_TRAFFIC', 'special_ad_categories' => ['NONE'],
            'daily_budget' => '5000', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'adsets' => ['data' => [[
                'id' => $id . '67890', 'name' => 'US/CA 18-45', 'daily_budget' => '5000',
                'billing_event' => 'IMPRESSIONS', 'optimization_goal' => 'LINK_CLICKS',
                'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
                'targeting' => ['geo_locations' => ['countries' => ['US', 'CA']], 'age_min' => 18, 'age_max' => 45, 'genders' => [1]],
                'start_time' => '2026-08-01T12:00:00+0000',
                'ads' => ['data' => [array_filter([
                    'id' => $id . '11111', 'name' => 'Ad 1',
                    'ad_review_feedback' => $reviewFb,
                    'creative' => ['effective_object_story_id' => '12345_98765', 'object_story_spec' => ['link_data' => [
                        'link' => 'https://example.com/summer', 'message' => 'Big summer sale!', 'name' => 'Summer Sale',
                        'description' => 'Up to 50% off', 'image_hash' => 'imgmock1',
                        'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://example.com/summer']],
                    ]]],
                ], fn ($v) => $v !== null)]],
            ]]],
        ];
    }
}
