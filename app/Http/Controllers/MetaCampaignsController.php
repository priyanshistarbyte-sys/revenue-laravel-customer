<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Meta campaign builder — a spreadsheet of draft campaigns (each with ad sets /
 * ads) stored as JSON in meta_campaign_drafts. Admin only. Draft-only for now;
 * publishing to the Meta Marketing API comes in a later phase.
 */
class MetaCampaignsController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Meta Campaigns';
        $activePage = 'meta_campaigns';
        $pdo = getDB();
        $flash = session('flash');

        if ($request->isMethod('post')) {
            $action = (string) $request->input('action', '');
            $need = ['pull' => 'edit', 'toggle' => 'edit', 'publish' => 'add', 'save' => 'add'];
            if (isset($need[$action]) && !userCan('meta_campaigns', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' Meta campaigns.');
            }
            if ($request->input('action') === 'pull')    return $this->pull($request);
            if ($request->input('action') === 'toggle')  return $this->toggle($request, $pdo);
            if ($request->input('action') === 'publish') return $this->publish($request, $pdo);
            if ($request->input('action') === 'save')    return $this->save($request, $pdo);
        }

        // ── Synced assets for the dropdowns ──
        $adAccounts = [];
        foreach ($pdo->query("SELECT aa.act_id, aa.name, aa.account_id, aa.meta_account_id
                              FROM meta_ad_accounts aa WHERE aa.active = 1 ORDER BY aa.name")->fetchAll() as $a) {
            $adAccounts[] = [
                'act_id'  => $a['act_id'],
                'ma'      => (int) $a['meta_account_id'],
                'label'   => trim(($a['name'] ?: $a['act_id']) . ' (' . $a['account_id'] . ')'),
            ];
        }
        $pages = array_map(fn ($p) => ['id' => $p['page_id'], 'name' => $p['name'], 'ma' => (int) $p['meta_account_id'], 'act' => $p['act_id'] ?? ''],
            $pdo->query("SELECT page_id, name, meta_account_id, act_id FROM meta_pages ORDER BY name")->fetchAll());
        $pixels = array_map(fn ($x) => ['id' => $x['pixel_id'], 'name' => $x['name'], 'ma' => (int) $x['meta_account_id'], 'act' => $x['act_id']],
            $pdo->query("SELECT pixel_id, name, meta_account_id, act_id FROM meta_pixels ORDER BY name")->fetchAll());

        $users = array_map(fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], allUsers());

        // ── Existing drafts for this admin ──
        $stmt = $pdo->prepare("SELECT id, status, data FROM meta_campaign_drafts WHERE owner_user_id = ? ORDER BY position, id");
        $stmt->execute([currentUserId()]);
        $drafts = [];
        foreach ($stmt->fetchAll() as $d) {
            $row = json_decode((string) $d['data'], true) ?: [];
            $row['_id']     = (int) $d['id'];
            $row['_status'] = $d['status'];
            $drafts[] = $row;
        }

        return view('meta_campaigns', compact(
            'pageTitle', 'activePage', 'flash', 'adAccounts', 'pages', 'pixels', 'users', 'drafts'
        ) + [
            'objectives'    => $this->objectives(),
            'countries'     => $this->countries(),
            'ctas'          => $this->ctas(),
            'bidStrategies' => $this->bidStrategies(),
            'lists'         => $this->lists(),
            'languages'     => $this->languages(),
            'media'         => $this->media(),
        ]);
    }

    /** Persist the posted grid as this admin's drafts; returns the new row ids in order. */
    private function persist(\PDO $pdo, int $owner, array $rows): array
    {
        $pdo->prepare("DELETE FROM meta_campaign_drafts WHERE owner_user_id = ?")->execute([$owner]);
        $ins = $pdo->prepare("INSERT INTO meta_campaign_drafts (owner_user_id, position, status, data, created_at, updated_at)
                              VALUES (?,?,?,?,NOW(),NOW())");
        $ids = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) { $ids[] = null; continue; }
            $status = $row['_status'] ?? 'draft';
            unset($row['_id'], $row['_status']);
            $ins->execute([$owner, $i, $status, json_encode($row)]);
            $ids[] = (int) $pdo->lastInsertId();
        }
        return $ids;
    }

    /** Pull ACTIVE campaigns from Meta and return them (reverse-mapped) as JSON
     *  for the builder to append as editable draft rows. */
    private function pull(Request $request)
    {
        $acts = json_decode((string) $request->input('acts', '[]'), true);
        if (!is_array($acts)) $acts = [];
        $res = (new \App\Services\MetaCampaignsPull())->pull((int) $request->input('account', 0), $acts);
        return response()->json($res);
    }

    /**
     * Activate / pause a published campaign on Meta (AJAX). Body: campaign_id,
     * account (act_id), status (ACTIVE|PAUSED). Persists the new status onto the
     * matching draft so a page refresh reflects it. Returns JSON.
     */
    private function toggle(Request $request, \PDO $pdo)
    {
        $campaignId = trim((string) $request->input('campaign_id', ''));
        $account    = trim((string) $request->input('account', ''));
        $status     = strtoupper(trim((string) $request->input('status', '')));
        if ($campaignId === '' || $account === '') {
            return response()->json(['ok' => false, 'error' => 'missing campaign or account'], 422);
        }

        $res = (new \App\Services\MetaPublisher())->setStatus($account, $campaignId, $status);

        if (!empty($res['ok'])) {
            $stmt = $pdo->prepare("SELECT id, data FROM meta_campaign_drafts WHERE owner_user_id = ?");
            $stmt->execute([(int) currentUserId()]);
            foreach ($stmt->fetchAll() as $d) {
                $row = json_decode((string) $d['data'], true) ?: [];
                $cid = (string) ($row['_src_campaign_id'] ?? ($row['_meta']['campaign_id'] ?? ''));
                if ($cid === $campaignId) {
                    $row['_meta'] = ($row['_meta'] ?? []);
                    $row['_meta']['status'] = $res['status'];
                    $pdo->prepare("UPDATE meta_campaign_drafts SET data = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([json_encode($row), (int) $d['id']]);
                    break;
                }
            }
        }
        return response()->json($res);
    }

    /** Replace this admin's drafts with the posted grid (JSON array of rows). */
    private function save(Request $request, \PDO $pdo)
    {
        $rows = json_decode((string) $request->input('grid_data', '[]'), true);
        if (!is_array($rows)) $rows = [];
        $ids = $this->persist($pdo, (int) currentUserId(), $rows);
        return redirect('/meta_campaigns')->with('flash', ['type' => 'success', 'msg' => 'Saved ' . count(array_filter($ids)) . ' campaign draft(s).']);
    }

    /**
     * Publish selected campaigns to Meta (everything created PAUSED). Saves the grid
     * first, then publishes the chosen indices, writing the result back onto each
     * draft (status published/failed + Meta ids / error) for the STATUS column.
     */
    private function publish(Request $request, \PDO $pdo)
    {
        $rows = json_decode((string) $request->input('grid_data', '[]'), true);
        if (!is_array($rows)) $rows = [];
        $owner = (int) currentUserId();
        $ids   = $this->persist($pdo, $owner, $rows);

        $idx = json_decode((string) $request->input('publish_idx', '[]'), true);
        if (!is_array($idx) || !$idx) $idx = range(0, max(0, count($rows) - 1));

        $publisher = new \App\Services\MetaPublisher();
        $upd = $pdo->prepare("UPDATE meta_campaign_drafts SET status = ?, data = ? WHERE id = ?");

        $ok = 0; $fail = 0; $errs = [];
        foreach ($idx as $i) {
            $i = (int) $i;
            if (!isset($rows[$i]) || !is_array($rows[$i])) continue;
            $row = $rows[$i];
            unset($row['_id'], $row['_status'], $row['_meta'], $row['_error']);

            $miss = $this->readyErrors($row);
            if ($miss) {
                $row['_error'] = 'Not ready: ' . $miss[0];
                $status = 'failed'; $fail++; $errs[] = ($row['camp_name'] ?? ('#' . ($i + 1))) . ' — ' . $row['_error'];
            } else {
                $res = $publisher->publish($row);
                if ($res['ok']) {
                    // The returned draft carries the Meta ids (_src_*) so later publishes update in place.
                    $row = $res['draft'];
                    // New objects are created ACTIVE; an in-place update keeps whatever
                    // status the toggle last set (default ACTIVE).
                    $liveStatus = $res['updated'] ? (string) ($rows[$i]['_meta']['status'] ?? 'ACTIVE') : 'ACTIVE';
                    $row['_meta'] = ['campaign_id' => $res['campaign_id'], 'updated' => $res['updated'], 'status' => $liveStatus];
                    $status = 'published'; $ok++;
                } else {
                    $row['_error'] = $res['error']; $status = 'failed'; $fail++;
                    $errs[] = ($row['camp_name'] ?? ('#' . ($i + 1))) . ' — ' . $res['error'];
                }
            }
            if (!empty($ids[$i])) $upd->execute([$status, json_encode($row), $ids[$i]]);
        }

        $mock = !empty(config('adledger.meta')['mock']);
        $msg  = "Published $ok campaign(s)" . ($mock ? ' [MOCK]' : '')
              . ($fail ? " · $fail failed: " . implode(' | ', array_slice($errs, 0, 4)) : '') . '.';
        return redirect('/meta_campaigns')->with('flash', ['type' => $fail ? 'error' : 'success', 'msg' => $msg]);
    }

    /** Minimal server-side readiness check mirroring the grid's validation. */
    private function readyErrors(array $c): array
    {
        $has = fn ($v) => trim((string) $v) !== '';
        $e = [];
        if (!$has($c['account'] ?? '')) $e[] = 'ad account required';
        if (!$has($c['camp_name'] ?? '')) $e[] = 'campaign name required';
        if (!$has($c['page'] ?? '')) $e[] = 'fan page required';
        $mode = strtoupper(trim((string) ($c['budget_mode'] ?? '')));
        $cbo  = $mode === 'CBO' ? true : ($mode === 'ABO' ? false : ((float) ($c['budget'] ?? 0)) > 0);
        if ($cbo && !(((float) ($c['budget'] ?? 0)) > 0)) $e[] = 'campaign budget required';
        foreach (($c['adsets'] ?? []) as $a) {
            if (!$has($a['adset_name'] ?? '')) $e[] = 'ad set name required';
            if (!$has($a['country'] ?? '')) $e[] = 'country required';
            if (!$cbo && !(((float) ($a['adset_budget'] ?? 0)) > 0)) $e[] = 'budget required';
            foreach (($a['ads'] ?? []) as $ad) {
                if (!$has($ad['ad_name'] ?? '')) $e[] = 'ad name required';
                if (!preg_match('~^https?://~i', trim((string) ($ad['dest_url'] ?? '')))) $e[] = 'valid destination URL required';
            }
        }
        return $e;
    }

    // ── Option lists ────────────────────────────────────────────────────
    private function objectives(): array
    {
        return [
            'OUTCOME_TRAFFIC'    => 'Traffic',
            'OUTCOME_SALES'      => 'Sales / Conversions',
            'OUTCOME_LEADS'      => 'Leads',
            'OUTCOME_ENGAGEMENT' => 'Engagement',
            'OUTCOME_AWARENESS'  => 'Awareness',
            'OUTCOME_APP_PROMOTION' => 'App promotion',
        ];
    }

    private function ctas(): array
    {
        return ['WATCH_MORE' => 'Watch More', 'LEARN_MORE' => 'Learn More', 'SHOP_NOW' => 'Shop Now', 'SIGN_UP' => 'Sign Up',
                'SUBSCRIBE' => 'Subscribe', 'GET_OFFER' => 'Get Offer', 'BOOK_TRAVEL' => 'Book Now',
                'DOWNLOAD' => 'Download', 'CONTACT_US' => 'Contact Us', 'APPLY_NOW' => 'Apply Now', 'NO_BUTTON' => 'No Button'];
    }

    private function bidStrategies(): array
    {
        return ['LOWEST_COST_WITHOUT_CAP' => 'Lowest Cost', 'LOWEST_COST_WITH_BID_CAP' => 'Bid Cap',
                'COST_CAP' => 'Cost Cap', 'LOWEST_COST_WITH_MIN_ROAS' => 'Min ROAS'];
    }

    /** All remaining dropdown option sets for the grid, keyed by column. */
    private function lists(): array
    {
        return [
            'special_category' => [
                'NONE' => 'None', 'CREDIT' => 'Credit', 'EMPLOYMENT' => 'Employment', 'HOUSING' => 'Housing',
                'ISSUES_ELECTIONS_POLITICS' => 'Social/Politics', 'FINANCIAL_PRODUCTS_SERVICES' => 'Financial', 'ONLINE_GAMBLING_AND_GAMING' => 'Gambling',
            ],
            'budget_mode' => ['CBO' => 'CBO', 'ABO' => 'ABO'],
            'budget_type' => ['DAILY' => 'Daily', 'LIFETIME' => 'Lifetime'],
            'conv_location' => [
                'WEBSITE' => 'Website', 'APP' => 'App', 'MESSENGER' => 'Messenger',
                'WHATSAPP' => 'WhatsApp', 'INSTAGRAM' => 'Instagram', 'CALLS' => 'Calls',
            ],
            'performance_goal' => [
                'LINK_CLICKS' => 'Link clicks', 'LANDING_PAGE_VIEWS' => 'Landing page views',
                'IMPRESSIONS' => 'Impressions', 'REACH' => 'Reach', 'DAILY_UNIQUE_REACH' => 'Daily unique reach',
                'OFFSITE_CONVERSIONS' => 'Conversions', 'THRUPLAY' => 'ThruPlay',
            ],
            'billing' => ['IMPRESSIONS' => 'Impressions', 'LINK_CLICKS' => 'Link clicks'],
            'event_type' => [
                '' => '—', 'PURCHASE' => 'Purchase', 'LEAD' => 'Lead', 'COMPLETE_REGISTRATION' => 'Registration',
                'ADD_TO_CART' => 'Add to cart', 'INITIATE_CHECKOUT' => 'Checkout', 'VIEW_CONTENT' => 'View content', 'CONTACT' => 'Contact',
            ],
            'attribution' => [
                '7d_click_1d_view' => '7d click, 1d view', '1d_click' => '1-day click',
                '7d_click' => '7-day click', '1d_view' => '1-day view',
            ],
            'device'    => ['all' => 'All', 'mobile' => 'Mobile', 'desktop' => 'Desktop'],
            'os'        => ['all' => 'All', 'iOS' => 'iOS', 'Android' => 'Android'],
            'placement' => ['automatic' => 'Automatic', 'manual' => 'Manual'],
            'platforms' => [
                'all'           => 'All platforms',
                'fb_ig'         => 'Facebook + Instagram',
                'facebook'      => 'Facebook only',
                'instagram'     => 'Instagram only',
                'fb_ig_msg'     => 'FB + IG + Messenger',
                'fb_ig_threads' => 'FB + IG + Threads',
            ],
            // Placement positions per platform (Meta enum => label). Empty = all positions.
            'fb_positions' => [
                'feed' => 'Feed', 'story' => 'Stories', 'facebook_reels' => 'Reels', 'marketplace' => 'Marketplace',
                'search' => 'Search Results', 'instream_video' => 'In-Stream Video', 'right_hand_column' => 'Right Column',
            ],
            'ig_positions' => [
                'stream' => 'Feed', 'story' => 'Stories', 'reels' => 'Reels', 'explore' => 'Explore',
                'profile_feed' => 'Profile Feed', 'ig_search' => 'Search',
            ],
            'an_positions'      => ['classic' => 'Native, Banner, Interstitial', 'rewarded_video' => 'Rewarded Video'],
            'msg_positions'     => ['messenger_home' => 'Messenger Inbox', 'story' => 'Messenger Stories'],
            'threads_positions' => ['threads_feed' => 'Feed'],
            'post'      => ['NEW' => 'New Post', 'EXISTING' => 'Existing Post'],
        ];
    }

    /**
     * Creative-Hub media library for the Video/Image pickers, grouped by kind.
     * Synced items (source='meta') land on the picker's Assets tab; uploads
     * (source='upload') on the Files tab. Each item: id, name, url, size, source.
     */
    private function media(): array
    {
        $out = ['video' => [], 'image' => []];
        try {
            $rows = getDB()->query(
                "SELECT kind, media_ref, name, url, size_bytes, source FROM meta_media ORDER BY source DESC, name"
            )->fetchAll();
        } catch (\Throwable $e) {
            return $out;   // table not migrated yet — degrade gracefully instead of 500ing the page
        }
        foreach ($rows as $r) {
            $kind = $r['kind'] === 'video' ? 'video' : 'image';
            $out[$kind][] = [
                'id'     => $r['media_ref'],
                'name'   => $r['name'] ?: $r['media_ref'],
                'url'    => $r['url'] ?: '',
                'size'   => humanBytes((int) $r['size_bytes']),
                'source' => $r['source'] ?: 'meta',
            ];
        }
        return $out;
    }

    /** Languages for the MULTILANGUAGE section (code => English name). */
    private function languages(): array
    {
        return [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'nl' => 'Dutch', 'ar' => 'Arabic',
            'hi' => 'Hindi', 'id' => 'Indonesian', 'ru' => 'Russian', 'ja' => 'Japanese',
            'ko' => 'Korean', 'zh' => 'Chinese', 'tr' => 'Turkish', 'pl' => 'Polish',
            'sv' => 'Swedish', 'th' => 'Thai', 'vi' => 'Vietnamese', 'ms' => 'Malay',
        ];
    }

    private function countries(): array
    {
        return [
            'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
            'IN' => 'India', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain',
            'NL' => 'Netherlands', 'BR' => 'Brazil', 'MX' => 'Mexico', 'AE' => 'UAE', 'SA' => 'Saudi Arabia',
            'ZA' => 'South Africa', 'SG' => 'Singapore', 'MY' => 'Malaysia', 'ID' => 'Indonesia',
            'PH' => 'Philippines', 'JP' => 'Japan', 'KR' => 'South Korea', 'SE' => 'Sweden', 'NO' => 'Norway',
            'DK' => 'Denmark', 'FI' => 'Finland', 'IE' => 'Ireland', 'NZ' => 'New Zealand', 'PL' => 'Poland',
            'PT' => 'Portugal', 'CH' => 'Switzerland', 'AT' => 'Austria', 'BE' => 'Belgium',
        ];
    }
}
