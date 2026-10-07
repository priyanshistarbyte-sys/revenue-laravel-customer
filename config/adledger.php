<?php

/**
 * Amaira sync settings — port of gam_config.php / meta_config.php.
 * The per-account credentials live on the ADX (GAM) and Meta Accounts pages;
 * this file holds the shared/global settings only.
 */
return [

    // ── GAM (Google Ad Manager) API sync ──
    'gam' => [
        'enabled'            => env('GAM_ENABLED', true),
        'lookback_days'      => (int) env('GAM_LOOKBACK_DAYS', 3),
        // Min gap between non-forced syncs. Under 1h so an hourly cron is never
        // skipped (last-sync is stamped when a run ends, a bit after :00).
        'min_interval_hours' => (float) env('GAM_MIN_INTERVAL_HOURS', 0.75),
        'cron_key'           => env('GAM_CRON_KEY', ''),
        // Cron expression for the scheduled `gam:sync` (routes/console.php).
        // Default: every hour on the hour. Empty = no scheduled sync.
        'schedule'           => env('GAM_SYNC_CRON', '0 * * * *'),
        'currency'           => env('GAM_CURRENCY', 'USD'),
        'mock'               => env('GAM_MOCK', false),
        // Pull data straight from each GAM network: the app creates and runs its own
        // hidden report (Site × Date, and Site × Date × Hour) — no saved report needed
        // in the GAM UI. Set false to use the ADX page's Saved/Hourly Report IDs instead.
        'direct_reports'     => env('GAM_DIRECT_REPORTS', true),
        // How direct mode fetches: 'soap' = ad-hoc ReportService jobs, nothing is
        // created in the GAM account; 'rest' = hidden REST v1 reports kept in GAM.
        'report_api'         => env('GAM_REPORT_API', 'soap'),
        // SOAP CSV_DUMP money columns are in micros; divide to get currency units.
        'soap_money_divisor' => (float) env('GAM_SOAP_MONEY_DIVISOR', 1000000),

        // Map the saved report's column keys → gam_data fields. Each entry may be a
        // ['dim'|'metric', index] position override; anything else falls back to the
        // sensible positional defaults in GamSync::normalizeRow().
        // Saved daily report metric order: Revenue, CTR, Impressions, Total requests, Clicks
        // (all Ad Exchange). Set an index to -1 to skip a metric.
        'column_map' => [
            'site'           => ['dim', 0],
            'date'           => ['dim', 1],
            'revenue_usd'    => ['metric', 0],
            'ctr'            => ['metric', (int) env('GAM_COL_CTR', 1)],
            'impressions'    => ['metric', (int) env('GAM_COL_IMPRESSIONS', 2)],
            'total_requests' => ['metric', (int) env('GAM_COL_REQUESTS', 3)],
            'clicks'         => ['metric', (int) env('GAM_COL_CLICKS', 4)],
            'match_rate'     => ['metric', -1],
            'ecpm'           => ['metric', -1],
        ],
        // Multiply the daily report's CTR before storing. The API returns a 0..1 ratio
        // (e.g. 0.0356 for 3.56%); the stored value is a percent.
        'ctr_scale' => (float) env('GAM_CTR_SCALE', 100),

        // Hourly Ad Exchange report (Site × Date × Hour with CTR) → gam_hourly (GAM
        // Check). Fetched by the same sync, using each ADX network's hourly_report_id.
        // Build the saved report's dimensions in this order: Site, Date, Hour.
        'hourly_column_map' => [
            'site'        => ['dim', 0],
            'date'        => ['dim', 1],    // report's Date dimension; if absent, falls back to the report day
            'hour'        => ['dim', 2],
            'ctr'         => ['metric', 0],
            'revenue'     => ['metric', -1],
            'impressions' => ['metric', -1],
            'total_requests' => ['metric', -1],
        ],
        // Multiply the API CTR value before storing (set 100 if the API returns a
        // 0..1 ratio instead of a percentage). Stored value should be a percent.
        'hourly_ctr_scale' => (float) env('GAM_HOURLY_CTR_SCALE', 1),

        // ── Ad unit creation (Domains page → "Create ad unit") ──
        // Uses the GAM SOAP API (InventoryService) with each ADX network's
        // service-account key. Needs the `dfp` scope + inventory-write permission.
        'ad_unit' => [
            'soap_version'     => env('GAM_SOAP_VERSION', 'v202605'),
            'application_name' => env('GAM_APP_NAME', 'Amaira'),
            // How many ad units to create per domain, named <base>1..<base>N
            // (base = the domain label before the first dot, e.g. erpnewswire).
            'count'            => (int) env('GAM_AD_UNIT_COUNT', 5),
            // Parent ad unit id under which new units are created; empty = the
            // network's effective root ad unit (fetched automatically).
            'parent_id'        => env('GAM_AD_UNIT_PARENT', ''),
            'target_window'    => env('GAM_AD_UNIT_TARGET', 'TOP'),   // BLANK | TOP
            // Fixed creative sizes attached to each new ad unit.
            'sizes'            => [],
        ],
    ],

    // ── Meta (Facebook) API sync ──
    'meta' => [
        'enabled'            => env('META_ENABLED', false),
        'lookback_days'      => (int) env('META_LOOKBACK_DAYS', 3),
        'min_interval_hours' => (float) env('META_MIN_INTERVAL_HOURS', 3),
        'cron_key'           => env('META_CRON_KEY', ''),
        'api_version'        => env('META_API_VERSION', 'v21.0'),
        'results_action'     => env('META_RESULTS_ACTION', ''),
        'mock'               => env('META_MOCK', false),
        // Show Meta campaigns + spend (and spend-based GST/cost/P&L/margin) in the
        // staff UI. false hides the Meta Campaigns page and those report columns.
        'show_in_ui'         => env('META_SHOW_IN_UI', false),
    ],
];
