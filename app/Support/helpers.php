<?php

/**
 * Amaira global helpers — a faithful Laravel port of the original config.php
 * helper functions. Kept as global functions (autoloaded via composer "files")
 * so the ported Blade views and controllers can call them exactly as the
 * original PHP did (getSetting(), fmtINR(), isAdmin(), scopeSQL(), …).
 *
 * Auth state lives in the Laravel session; data access goes through the
 * framework's PDO connection so the original SQL ports over verbatim.
 */

use Illuminate\Support\Facades\DB;

if (!defined('AUTH_SESSION_TTL')) {
    define('AUTH_SESSION_TTL', (int) env('AUTH_SESSION_TTL', 3 * 3600)); // 3 hours
}

/**
 * The underlying PDO handle, configured to match the original app
 * (associative fetch, exceptions on error).
 */
function getDB(): PDO
{
    static $configured = false;
    $pdo = DB::connection()->getPdo();
    if (!$configured) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $configured = true;
    }
    return $pdo;
}

// ── Settings ────────────────────────────────────────────────────────
function getSetting(string $key, string $default = ''): string
{
    try {
        $stmt = getDB()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['setting_value'] : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

// ── Currency Master helpers ─────────────────────────────────────────
function getDefaultCurrency(): array
{
    static $cur = null;
    if ($cur !== null) return $cur;
    try {
        $row = getDB()->query("SELECT * FROM currencies WHERE is_default = 1 LIMIT 1")->fetch();
        $cur = $row ?: ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'rate_to_default' => 1];
    } catch (\Throwable $e) {
        $cur = ['code' => 'INR', 'symbol' => '₹', 'name' => 'Indian Rupee', 'rate_to_default' => 1];
    }
    return $cur;
}

function getAllCurrencies(bool $activeOnly = true): array
{
    try {
        $sql = "SELECT * FROM currencies" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY is_default DESC, code ASC";
        return getDB()->query($sql)->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

/** Rate of 1 unit of $code expressed in the default currency. */
function getCurrencyRate(string $code): float
{
    static $rates = null;
    if ($rates === null) {
        $rates = [];
        try {
            foreach (getDB()->query("SELECT code, rate_to_default FROM currencies WHERE active = 1")->fetchAll() as $r) {
                $rates[$r['code']] = (float) $r['rate_to_default'];
            }
        } catch (\Throwable $e) {}
    }
    return $rates[$code] ?? 1.0;
}

function convertToDefault(float $amount, string $fromCode): float
{
    return round($amount * getCurrencyRate($fromCode), 4);
}

function fmtMoney(float $amount): string
{
    $cur    = getDefaultCurrency();
    $sign   = $amount < 0 ? '-' : '';
    $symbol = $cur['symbol'] ?? '₹';
    if (($cur['code'] ?? 'INR') === 'INR') {
        return $sign . $symbol . format_inr(abs($amount));
    }
    return $sign . $symbol . number_format(abs($amount), 2);
}

function fmtINR(float $amount): string
{
    return fmtMoney($amount);
}

function format_inr(float $number): string
{
    $number    = abs($number);
    $exploded  = explode('.', number_format($number, 2, '.', ''));
    $integer   = $exploded[0];
    $decimal   = $exploded[1] ?? '00';

    $last_three = substr($integer, -3);
    $rest       = substr($integer, 0, -3);

    if ($rest !== '') {
        $rest = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest) . ",";
    }

    return $rest . $last_three . '.' . $decimal;
}

function fmtPct(float $pct): string
{
    return number_format($pct, 1) . '%';
}

/** Format a default-currency (INR) amount as its USD equivalent, given the USD→INR rate. */
function fmtUsdFromInr(float $inr, float $rate): string
{
    return '$' . number_format($rate > 0 ? $inr / $rate : 0, 2);
}

/**
 * Ad unit base name for a GAM URL: every host label except the TLD, joined
 * (subdomains are kept so each subdomain gets its own ad units).
 * e.g. "dailyblogzone.com"      → "dailyblogzone"
 *      "eco1.dailyblogzone.com" → "eco1dailyblogzone"
 *      "ab1.alopino.co.uk"      → "ab1alopino"
 */
function resolveAdUnit(string $gamUrl): string
{
    $hosts = array_filter(array_map('trim', explode(',', $gamUrl)));
    $host  = normalizeUrl($hosts[0] ?? '');
    if ($host === '') return '';
    $host  = preg_replace('/^www\./', '', $host);
    $parts = explode('.', $host);
    $n     = count($parts);
    if ($n >= 2) {
        // Drop the TLD — two labels for two-level TLDs (co.uk etc.).
        $twoLevel = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac'];
        $drop  = ($n >= 3 && in_array($parts[$n - 2], $twoLevel, true) && strlen($parts[$n - 1]) <= 3) ? 2 : 1;
        $parts = array_slice($parts, 0, $n - $drop);
    }
    return preg_replace('/[^a-z0-9]+/', '', implode('', $parts));
}

/**
 * Build the bash lines that replace each [TOKEN] in the deployed files with its
 * value (grep + sed, one line per token). Used by the deployment services.
 * Values are escaped for the sed `s#...#...#` command.
 */
function buildReplaceBlock(array $replacements): string
{
    $lines = [];
    foreach ($replacements as $token => $value) {
        $token = preg_replace('/[^A-Za-z0-9_]/', '', (string) $token);   // simple identifier
        if ($token === '' || $value === null || $value === '') continue;  // skip empty
        $valEsc = str_replace(['\\', '#', '&'], ['\\\\', '\\#', '\\&'], (string) $value);
        $lines[] = "grep -rFl '[{$token}]' \"\$REMOTE_PATH\" 2>/dev/null | xargs -r sed -i 's#\\[{$token}\\]#{$valEsc}#g'";
    }
    return $lines ? implode("\n", $lines) : ':';
}

/**
 * Build the deployment credential array from a `servers` DB row, decrypting the
 * secret fields. The result is passed to DeploymentService/MainSiteDeploymentService.
 */
function serverConfig(array $srv): array
{
    $dec = function ($v) {
        if ($v === null || $v === '') return $v;
        try { return \Illuminate\Support\Facades\Crypt::decryptString($v); }
        catch (\Throwable $e) { return $v; }   // tolerate any legacy plaintext
    };

    return [
        'ssh_host'             => $srv['ssh_host'] ?? null,
        'ssh_port'             => $srv['ssh_port'] ?? 22,
        'ssh_username'         => $srv['ssh_username'] ?? null,
        'ssh_password'         => $dec($srv['ssh_password'] ?? null),
        'bt_panel_url'         => $srv['bt_panel_url'] ?? null,
        'bt_panel_key'         => $dec($srv['bt_panel_key'] ?? null),
        'bt_panel_php_version' => $srv['bt_panel_php_version'] ?? '74',
    ];
}

/**
 * Derive the MAIN_URL value for a sub-site from its GAM URL list: the FULL
 * LAST host in the list (normalized — scheme/path stripped, lowercased).
 * e.g. "dailyblogzone.com,blogpage.com"         → "blogpage.com"
 *      "erp.dailyblogzone.com,emp.blogpage.com" → "emp.blogpage.com"
 */
function resolveMainUrl(string $gamUrl): string
{
    $hosts = array_values(array_filter(array_map(fn($h) => normalizeUrl(trim($h)), explode(',', $gamUrl))));
    if (empty($hosts)) return '';
    return end($hosts);
}

/** Reduce a URL to its bare hostname (protocol/path/query stripped). */
function normalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $withScheme = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
    $host = parse_url($withScheme, PHP_URL_HOST);
    if (!$host) {
        $host = preg_replace('#^https?://#i', '', $url);
        $host = explode('/', $host)[0];
    }
    return strtolower(rtrim($host, '/'));
}

/**
 * Best-effort registrable ("root") domain of a host — the name a main domain
 * would be stored under. e.g. ew1.erpnewswire.com → erpnewswire.com.
 * Handles common two-level public suffixes (co.uk, com.au, co.in, …).
 */
function registrableDomain(string $host): string
{
    $host = normalizeUrl($host);
    if ($host === '') return '';
    $host  = preg_replace('/^www\./', '', $host);
    $parts = explode('.', $host);
    $n     = count($parts);
    if ($n <= 2) return $host;

    $twoLevel = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac'];
    if (in_array($parts[$n - 2], $twoLevel, true) && strlen($parts[$n - 1]) <= 3) {
        return implode('.', array_slice($parts, -3));
    }
    return implode('.', array_slice($parts, -2));
}

/**
 * Find the existing main domain a host belongs to: the domain whose name the
 * host equals or is a subdomain of (longest match wins). Returns row or null.
 */
function matchDomainRow(\PDO $pdo, string $host): ?array
{
    $host = normalizeUrl($host);
    if ($host === '') return null;

    $best = null; $bestLen = -1;
    foreach ($pdo->query("SELECT id, name FROM domains")->fetchAll() as $r) {
        $name = strtolower(trim((string) $r['name']));
        if ($name === '') continue;
        if ($host === $name || str_ends_with($host, '.' . $name)) {
            if (strlen($name) > $bestLen) { $best = $r; $bestLen = strlen($name); }
        }
    }
    return $best;
}

// ── Users / PIN auth ────────────────────────────────────────────────
function hasUsers(): bool
{
    try {
        return (int) getDB()->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Find the (active) user whose PIN matches. */
function verifyUserPin(string $pin): ?array
{
    try {
        $users = getDB()->query("SELECT * FROM users WHERE active = 1")->fetchAll();
    } catch (\Throwable $e) {
        return null;
    }
    foreach ($users as $u) {
        if (password_verify($pin, $u['pin_hash'])) return $u;
    }
    return null;
}

/** True if any user (active or not) already uses this PIN, excluding $excludeId. */
function pinInUse(string $pin, int $excludeId = 0): bool
{
    try {
        $users = getDB()->query("SELECT id, pin_hash FROM users")->fetchAll();
    } catch (\Throwable $e) {
        return false;
    }
    foreach ($users as $u) {
        if ((int) $u['id'] !== $excludeId && password_verify($pin, $u['pin_hash'])) return true;
    }
    return false;
}

function userById(int $id): ?array
{
    try {
        $stmt = getDB()->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function hasSecondPin(array $u): bool
{
    return !empty($u['pin2_hash'] ?? null);
}

function verifySecondPin(array $u, string $pin): bool
{
    return hasSecondPin($u) && password_verify($pin, $u['pin2_hash']);
}

/** True when this user must clear a second PIN before the session is authenticated. */
function needsSecondPin(array $u): bool
{
    return hasSecondPin($u);
}

function setSecondPin(int $userId, ?string $pin): void
{
    getDB()->prepare("UPDATE users SET pin2_hash = ? WHERE id = ?")
           ->execute([$pin === null ? null : password_hash($pin, PASSWORD_BCRYPT), $userId]);
}

function createUser(string $name, string $pin, bool $isAdmin = false): int
{
    $pdo = getDB();
    $pdo->prepare("INSERT INTO users (name, pin_hash, is_admin, active, created_at, updated_at) VALUES (?,?,?,1,NOW(),NOW())")
        ->execute([$name, password_hash($pin, PASSWORD_BCRYPT), $isAdmin ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

function allUsers(bool $activeOnly = false): array
{
    try {
        $sql = "SELECT id, name, is_admin, active, created_at FROM users"
             . ($activeOnly ? " WHERE active = 1" : "")
             . " ORDER BY is_admin DESC, name ASC";
        return getDB()->query($sql)->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

// ── Current session user ────────────────────────────────────────────
function currentUserId(): int    { return (int) session('user_id', 0); }
function currentUserName(): string { return (string) session('user_name', ''); }
function isAdmin(): bool          { return (bool) session('is_admin', false); }

function userName(int $id): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (allUsers() as $u) $map[(int) $u['id']] = $u['name'];
    }
    return $map[$id] ?? '—';
}

/**
 * WHERE fragment restricting a query to the current user's rows.
 * Admins and users whose data_scope is 'all' see everything; otherwise the
 * query is limited to the user's own rows. (For link-centric queries that must
 * also honour the 'adx' scope, use linkScopeWhere().)
 */
function scopeSQL(string $col = 'user_id'): string
{
    if (isAdmin() || userDataScope() === 'all') return '1=1';
    return $col . ' = ' . currentUserId();
}

/** Owner for a new/updated row: admins may post owner_id (validated). */
function resolveOwnerId(): int
{
    if (canSeeAllUsers() && !empty(request()->input('owner_id'))) {
        $id = (int) request()->input('owner_id');
        foreach (allUsers() as $u) {
            if ((int) $u['id'] === $id) return $id;
        }
    }
    return currentUserId();
}

/** Owner <select> shown only to admins inside add/edit forms. */
function ownerSelectHTML(int $selected = 0, string $label = 'Owner (user)'): string
{
    if (!canSeeAllUsers()) return '';
    if ($selected <= 0) $selected = currentUserId();
    $html = '<div class="col-md-6"><label class="form-label">' . htmlspecialchars($label)
          . ' <small style="color:#7dd3fc;font-weight:400">(whose data is this?)</small></label>'
          . '<select name="owner_id" class="form-select">';
    foreach (allUsers() as $u) {
        $sel   = (int) $u['id'] === $selected ? ' selected' : '';
        $badge = $u['is_admin'] ? ' (admin)' : '';
        $html .= '<option value="' . (int) $u['id'] . '"' . $sel . '>'
               . htmlspecialchars($u['name'] . $badge) . '</option>';
    }
    return $html . '</select></div>';
}

// ── Session auth lifecycle ──────────────────────────────────────────
function isAuthenticated(): bool
{
    if (!session('auth_ok') || !session('auth_time') || !session('user_id')) return false;
    if (time() - (int) session('auth_time') > AUTH_SESSION_TTL) {
        authLogout();
        return false;
    }
    return true;
}

function authLogin(array $user): void
{
    session([
        'auth_ok'   => true,
        'auth_time' => time(),
        'user_id'   => (int) $user['id'],
        'user_name' => $user['name'],
        'is_admin'  => (int) $user['is_admin'] === 1,
    ]);
}

function authLogout(): void
{
    session()->forget(['auth_ok', 'auth_time', 'user_id', 'user_name', 'is_admin']);
}

/** Unix timestamp when the current session's PIN auth expires, or null. */
function authExpiresAt(): ?int
{
    if (!session('auth_time')) return null;
    return (int) session('auth_time') + AUTH_SESSION_TTL;
}

// ── Customer panel auth (separate from the staff PIN session) ───────
function customerAuthenticated(): bool
{
    if (!session('customer_id') || !session('customer_time')) return false;
    if (time() - (int) session('customer_time') > AUTH_SESSION_TTL) {
        customerLogout();
        return false;
    }
    return true;
}

function customerLogin(array $customer): void
{
    session([
        'customer_id'   => (int) $customer['id'],
        'customer_name' => $customer['name'],
        'customer_time' => time(),
    ]);
}

function customerLogout(): void
{
    session()->forget(['customer_id', 'customer_name', 'customer_time']);
}

function currentCustomerId(): int      { return (int) session('customer_id', 0); }
function currentCustomerName(): string { return (string) session('customer_name', ''); }

// ── invoices.php view helpers ───────────────────────────────────────
function fmtBytes(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function iconForMime(string $mime): string
{
    if ($mime === 'application/pdf')     return 'bi-file-earmark-pdf-fill';
    if (str_starts_with($mime, 'image')) return 'bi-file-earmark-image-fill';
    if (str_contains($mime, 'word'))     return 'bi-file-earmark-word-fill';
    if (str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet')) return 'bi-file-earmark-excel-fill';
    return 'bi-file-earmark-fill';
}

// ── Access control: pages, permissions & data scope ─────────────────
/**
 * The permission-managed pages, in nav order. Each entry declares the actions
 * that apply to it; 'path' is the URL when it differs from '/<key>'. Every page
 * and action is grantable — nothing is reserved for admins (who simply have
 * everything). The basic Settings page (own PIN) stays open to every user; the
 * 'settings' entry covers the system settings (GST, DB info) on it.
 */
function permissionPages(): array
{
    return [
        'dashboard'  => ['label' => 'Dashboard', 'icon' => 'bi-speedometer2',      'actions' => ['view', 'edit']],
        'monthly'    => ['label' => 'Monthly',   'icon' => 'bi-calendar3',         'actions' => ['view']],
        'date_wise'  => ['label' => 'Date Wise / Range', 'icon' => 'bi-clock-history', 'actions' => ['view'], 'path' => '/date-wise'],
        'gam_check'  => ['label' => 'GAM Check',  'icon' => 'bi-clipboard-pulse',   'actions' => ['view', 'edit']],
        'meta_campaigns' => ['label' => 'Meta Campaigns', 'icon' => 'bi-megaphone', 'actions' => ['view', 'add', 'edit']],
        'domains'    => ['label' => 'Domains',   'icon' => 'bi-globe2',            'actions' => ['view', 'add', 'edit', 'delete']],
        'links'      => ['label' => 'Links',     'icon' => 'bi-link-45deg',        'actions' => ['view', 'add', 'edit', 'delete']],
        'adx'        => ['label' => 'ADX',       'icon' => 'bi-diagram-3',         'actions' => ['view', 'add', 'edit', 'delete']],
        'upload'     => ['label' => 'Upload',    'icon' => 'bi-cloud-upload',      'actions' => ['view', 'add', 'delete']],
        'expenses'   => ['label' => 'Expenses',  'icon' => 'bi-wallet2',           'actions' => ['view', 'add', 'edit', 'delete']],
        'invoices'   => ['label' => 'Invoices',  'icon' => 'bi-file-earmark-text', 'actions' => ['view', 'add', 'edit', 'delete']],
        'currencies' => ['label' => 'Currency',  'icon' => 'bi-currency-exchange', 'actions' => ['view', 'add', 'edit', 'delete']],
        'users'      => ['label' => 'Users',     'icon' => 'bi-people',            'actions' => ['view', 'add', 'edit', 'delete']],
        'customers'  => ['label' => 'Customers', 'icon' => 'bi-person-vcard',     'actions' => ['view', 'add', 'edit', 'delete']],
        'roles'      => ['label' => 'Roles',     'icon' => 'bi-person-lock',       'actions' => ['view', 'add', 'edit', 'delete']],
        'zip_masters'    => ['label' => 'Zip',     'icon' => 'bi-file-zip',    'actions' => ['view', 'add', 'edit', 'delete'], 'path' => '/zip-masters'],
        'server_masters' => ['label' => 'Servers', 'icon' => 'bi-hdd-network', 'actions' => ['view', 'add', 'edit', 'delete'], 'path' => '/server-masters'],
        'settings'   => ['label' => 'System Settings', 'icon' => 'bi-gear',     'actions' => ['view', 'edit']],
    ];
}

/** The current user's permission grid: page => ['view'=>bool, 'add'=>…]. Cached per request. */
function loadUserPermissions(): array
{
    static $cache = null, $cachedFor = null;
    $uid = currentUserId();
    if ($cache !== null && $cachedFor === $uid) return $cache;
    $cache = []; $cachedFor = $uid;
    if ($uid <= 0) return $cache;
    try {
        $stmt = getDB()->prepare("SELECT page, can_view, can_add, can_edit, can_delete FROM user_permissions WHERE user_id = ?");
        $stmt->execute([$uid]);
        foreach ($stmt->fetchAll() as $r) {
            $cache[$r['page']] = [
                'view'   => (bool) $r['can_view'],
                'add'    => (bool) $r['can_add'],
                'edit'   => (bool) $r['can_edit'],
                'delete' => (bool) $r['can_delete'],
            ];
        }
    } catch (\Throwable $e) {}
    return $cache;
}

/**
 * Whether Meta campaigns and spend (and everything derived from spend: GST,
 * total cost, P/L, margin, profit/loss) are shown in the staff UI.
 * Off by default — set META_SHOW_IN_UI=true to bring them back.
 */
function showMeta(): bool
{
    return (bool) config('adledger.meta.show_in_ui', false);
}

/** True if the current user may perform $action on $page. Admins may do anything. */
function userCan(string $page, string $action = 'view'): bool
{
    // Meta Campaigns is switched off entirely while Meta is hidden (see showMeta()).
    if ($page === 'meta_campaigns' && !showMeta()) return false;
    if (isAdmin()) return true;
    $perms = loadUserPermissions();
    return !empty($perms[$page][$action]);
}

/** The current user's data-visibility scope: 'own' | 'adx' | 'all'. Admins are always 'all'. */
function userDataScope(): string
{
    if (isAdmin()) return 'all';
    static $scope = null, $cachedFor = null;
    $uid = currentUserId();
    if ($scope !== null && $cachedFor === $uid) return $scope;
    $cachedFor = $uid; $scope = 'own';
    if ($uid <= 0) return $scope;
    try {
        $stmt = getDB()->prepare("SELECT data_scope FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $v = (string) $stmt->fetchColumn();
        $scope = in_array($v, ['own', 'adx', 'all'], true) ? $v : 'own';
    } catch (\Throwable $e) {}
    return $scope;
}

/**
 * True when the current user sees every user's data (admin, or data scope 'all').
 * Gates the per-user filter, the owner column and choosing a row's owner.
 */
function canSeeAllUsers(): bool
{
    return userDataScope() === 'all';
}

/** ADX network ids this user may see when data_scope = 'adx'. */
function userAllowedAdx(): array
{
    static $ids = null, $cachedFor = null;
    $uid = currentUserId();
    if ($ids !== null && $cachedFor === $uid) return $ids;
    $cachedFor = $uid; $ids = [];
    if ($uid <= 0) return $ids;
    try {
        $stmt = getDB()->prepare("SELECT adx_id FROM user_allowed_adx WHERE user_id = ?");
        $stmt->execute([$uid]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    } catch (\Throwable $e) {}
    return $ids;
}

/**
 * Active ADX networks the current user may see in filters (Dashboard/Monthly).
 * ADX-scoped users only get their allowed networks; admins / 'all' / 'own' see all.
 */
function adxOptionsForUser(): array
{
    $where = "active = 1";
    if (!isAdmin() && userDataScope() === 'adx') {
        $ids = userAllowedAdx();
        if (empty($ids)) return [];
        $where .= " AND id IN (" . implode(',', array_map('intval', $ids)) . ")";
    }
    try {
        return getDB()->query("SELECT id, name FROM adx WHERE $where ORDER BY name")->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * WHERE fragment for link-centric queries (dashboard / monthly / links) that
 * honours the data scope. Assumes the links table is aliased $l and its parent
 * domain joined as $d (LEFT JOIN domains d ON d.id = l.domain_id).
 *   own → l.user_id = self · all → 1=1 · adx → COALESCE(d.adx_id,l.adx_id) IN (…)
 */
function linkScopeWhere(string $l = 'l', string $d = 'd'): string
{
    $scope = userDataScope();
    if ($scope === 'all') return '1=1';
    if ($scope === 'adx') {
        $ids = userAllowedAdx();
        if (empty($ids)) return '1=0';
        return "COALESCE($d.adx_id, $l.adx_id) IN (" . implode(',', array_map('intval', $ids)) . ")";
    }
    return "$l.user_id = " . currentUserId();
}

/** First path the current user is allowed to view — used as the post-login landing. */
function firstAllowedPath(): string
{
    if (isAdmin()) return '/';
    foreach (permissionPages() as $page => $meta) {
        if (userCan($page, 'view')) {
            return $meta['path'] ?? ($page === 'dashboard' ? '/' : '/' . $page);
        }
    }
    return '/settings';
}

/** Human-readable byte size, e.g. 247808 → "242 KB". */
function humanBytes(?int $b): string
{
    if (!$b) return '';
    $u = ['B', 'KB', 'MB', 'GB']; $i = 0; $n = (float) $b;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return round($n, $n < 10 ? 1 : 0) . ' ' . $u[$i];
}
