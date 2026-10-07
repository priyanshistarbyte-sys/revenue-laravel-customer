@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">UPLOAD DATA</div>
        <div class="dash-subtitle">{{ showMeta() ? 'Upload your Meta Ads spend CSV and GAM revenue CSV files' : 'Upload your GAM revenue CSV files' }}</div>
    </div>
</div>

@if (userCan('upload', 'add'))
<div class="data-card" style="padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:8px">
        <i class="bi bi-cloud-arrow-down" style="font-size:1.3rem;color:#a78bfa"></i>
        <div>
            <div style="color:#fff;font-size:13px;font-weight:600">GAM API auto-sync <small style="color:var(--text-muted);font-weight:400">— pulls all GAM accounts every hour (global)</small></div>
            <div style="font-size:11px;color:var(--text-muted)">
                <strong style="color:#c4b5fd">{{ $gamAcctCount }}</strong> active account{{ $gamAcctCount != 1 ? 's' : '' }}
                · last sync: <strong style="color:#c4b5fd">{{ $gamLastSync ?: 'never' }}</strong>
                @if (!$gamCfgReady)<span style="color:#ff7777">· GAM sync disabled (set GAM_ENABLED=true)</span>@endif
            </div>
        </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <a href="{{ url('/adx') }}" class="btn-sm-custom"><i class="bi bi-hdd-network"></i> Manage GAM accounts (ADX)</a>
        @if ($gamCfgReady && $gamAcctCount > 0)
        <a href="{{ url('/gam_sync?force=1') }}" class="btn-primary-custom"><i class="bi bi-arrow-repeat"></i> Sync now</a>
        @endif
    </div>
</div>
@endif

<div class="row g-4">
    @if (showMeta())
    <!-- Meta Upload -->
    <div class="col-md-6">
        <div class="upload-card">
            <h5 style="color:#ff8c00;margin-bottom:6px"><i class="bi bi-meta"></i> Meta Ads — Spend CSV</h5>
            <p style="color:var(--text-muted);font-size:12px;margin-bottom:18px">
                Required columns: <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Campaign name</code>,
                <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Day</code>,
                <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Amount spent (INR)</code>
            </p>
            <p style="color:var(--text-muted);font-size:11px;margin-bottom:18px;margin-top:-8px">
                <i class="bi bi-link-45deg" style="color:#a78bfa"></i>
                Optional: a <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Website URL</code> column
                will auto-link campaigns to matching Links.
            </p>
            <form method="post" action="{{ url('/upload') }}" enctype="multipart/form-data">
                <input type="hidden" name="type" value="meta">
                <div class="mb-3">
                    <label class="form-label">Report Currency
                        <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— currency the "Amount spent" column is in</small>
                    </label>
                    <select name="currency" class="form-select">
                        @foreach (getAllCurrencies() as $cur)
                        <option value="{{ $cur['code'] }}" {{ $cur['code'] === 'USD' ? 'selected' : '' }}>
                            {{ $cur['symbol'] . ' ' . $cur['code'] . ' — ' . $cur['name'] }}{{ $cur['code'] === 'USD' ? ' (default)' : '' }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="upload-zone" style="margin-bottom:14px">
                    <input type="file" name="csv_file" accept=".csv" style="display:none">
                    <i class="bi bi-filetype-csv d-block"></i>
                    <p class="file-name" style="margin-top:8px">Drop Meta CSV here or click to browse</p>
                    <p style="font-size:11px;margin-top:4px">Accepts .csv files only</p>
                </div>
                <button type="submit" class="btn-primary-custom w-100" style="justify-content:center">
                    <i class="bi bi-upload"></i> Upload Meta CSV
                </button>
            </form>
            <details style="margin-top:16px">
                <summary style="color:#a78bfa;cursor:pointer;font-size:12px">Show expected CSV format</summary>
                <div style="background:#0d0d22;border-radius:6px;padding:12px;margin-top:10px;overflow-x:auto">
                    <code style="font-size:11px;color:#7dd3fc;white-space:pre">Campaign name,Day,Delivery status,...,Amount spent (INR),...
ewire1,2026-06-04,active,...,2667.15,...
ewire2,2026-06-04,active,...,1773.87,...</code>
                </div>
            </details>
        </div>

        @if ($recentMeta)
        <div class="data-card">
            <div class="data-card-header"><i class="bi bi-clock-history"></i> Recent Meta Data (by date)</div>
            <table class="ledger" style="font-size:11px">
                <thead><tr><th style="text-align:left">Date</th><th>Campaigns</th><th>Total Spend (INR)</th></tr></thead>
                <tbody>
                @foreach ($recentMeta as $r)
                <tr>
                    <td style="text-align:left">{{ $r['date'] }}</td>
                    <td>{{ $r['cnt'] }}</td>
                    <td class="c-orange">{{ fmtINR((float)$r['total']) }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    <!-- GAM Upload -->
    <div class="{{ showMeta() ? 'col-md-6' : 'col-12' }}">
        <div class="upload-card">
            <h5 style="color:#00c853;margin-bottom:6px"><i class="bi bi-graph-up"></i> Google Ad Manager — Revenue CSV</h5>
            <p style="color:var(--text-muted);font-size:12px;margin-bottom:18px">
                Required columns: <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Site</code>,
                <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Date</code>,
                <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Ad Exchange revenue</code>
                — GAM exports include metadata rows at top, they are auto-skipped.
            </p>
            <form method="post" action="{{ url('/upload') }}" enctype="multipart/form-data">
                <input type="hidden" name="type" value="gam">
                <div class="mb-3">
                    <label class="form-label">Report Currency
                        <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— currency the "Ad Exchange revenue" column is in (stored as USD equivalent)</small>
                    </label>
                    <select name="currency" class="form-select">
                        @foreach (getAllCurrencies() as $cur)
                        <option value="{{ $cur['code'] }}" {{ $cur['code'] === 'USD' ? 'selected' : '' }}>
                            {{ $cur['symbol'] . ' ' . $cur['code'] . ' — ' . $cur['name'] }}{{ $cur['code'] === 'USD' ? ' (default)' : '' }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="upload-zone" style="margin-bottom:14px">
                    <input type="file" name="csv_file" accept=".csv" style="display:none">
                    <i class="bi bi-filetype-csv d-block"></i>
                    <p class="file-name" style="margin-top:8px">Drop GAM CSV here or click to browse</p>
                    <p style="font-size:11px;margin-top:4px">Accepts .csv files only</p>
                </div>
                <button type="submit" class="btn-primary-custom w-100" style="justify-content:center;background:#1a6e3c">
                    <i class="bi bi-upload"></i> Upload GAM CSV
                </button>
            </form>
            <details style="margin-top:16px">
                <summary style="color:#a78bfa;cursor:pointer;font-size:12px">Show expected CSV format</summary>
                <div style="background:#0d0d22;border-radius:6px;padding:12px;margin-top:10px;overflow-x:auto">
                    <code style="font-size:11px;color:#7dd3fc;white-space:pre">Report name,PM
Report type,Historical
...metadata rows auto-skipped...

Site,Date,Ad Exchange revenue,...
ew1.erpnewswire.com,2026-06-03,113.48,...
ew2.erpnewswire.com,2026-06-03,72.34,...</code>
                </div>
            </details>
        </div>

        @if ($recentGAM)
        <div class="data-card">
            <div class="data-card-header"><i class="bi bi-clock-history"></i> Recent GAM Data (by date)</div>
            <table class="ledger" style="font-size:11px">
                <thead><tr><th style="text-align:left">Date</th><th>Sites</th><th>Total Revenue (USD)</th></tr></thead>
                <tbody>
                @foreach ($recentGAM as $r)
                <tr>
                    <td style="text-align:left">{{ $r['date'] }}</td>
                    <td>{{ $r['cnt'] }}</td>
                    <td class="c-green">${{ number_format((float)$r['total'], 2) }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

<!-- Hourly GAM Upload (GAM Check) -->
<div class="upload-card" style="margin-top:24px">
    <h5 style="color:#7dd3fc;margin-bottom:6px"><i class="bi bi-clipboard-pulse"></i> GAM Hourly — Ad Exchange CTR (for GAM Check)</h5>
    <p style="color:var(--text-muted);font-size:12px;margin-bottom:14px">
        Export the GAM <strong>Interactive report</strong> with dimensions <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Site</code>,
        <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Hour</code> and metric
        <code style="background:#1a1a42;padding:0 4px;border-radius:3px">Ad Exchange CTR</code>
        (revenue &amp; impressions optional). Metadata rows at the top are auto-skipped. Feeds the
        <a href="{{ url('/gam_check') }}" style="color:#a78bfa">GAM Check</a> page.
    </p>
    <form method="post" action="{{ url('/upload') }}" enctype="multipart/form-data">
        <input type="hidden" name="type" value="hourly">
        <div class="row g-3" style="margin-bottom:6px">
            <div class="col-md-4">
                <label class="form-label">Report Date
                    <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— the day this report covers</small>
                </label>
                <input type="date" name="report_date" class="form-control" value="{{ date('Y-m-d', strtotime('yesterday')) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Report Currency</label>
                <select name="currency" class="form-select">
                    @foreach (getAllCurrencies() as $cur)
                    <option value="{{ $cur['code'] }}" {{ $cur['code'] === 'USD' ? 'selected' : '' }}>
                        {{ $cur['symbol'] . ' ' . $cur['code'] }}
                    </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="upload-zone" style="margin:8px 0 14px">
            <input type="file" name="csv_file" accept=".csv" style="display:none">
            <i class="bi bi-filetype-csv d-block"></i>
            <p class="file-name" style="margin-top:8px">Drop hourly GAM CSV here or click to browse</p>
            <p style="font-size:11px;margin-top:4px">Accepts .csv files only</p>
        </div>
        <button type="submit" class="btn-primary-custom" style="justify-content:center;background:#0d5a72">
            <i class="bi bi-upload"></i> Upload Hourly CSV
        </button>
    </form>
    @if ($recentHourly)
    <div class="data-card" style="margin-top:16px">
        <div class="data-card-header"><i class="bi bi-clock-history"></i> Recent Hourly Data (by date)</div>
        <table class="ledger" style="font-size:11px">
            <thead><tr><th style="text-align:left">Date</th><th>Sites</th><th>Rows</th></tr></thead>
            <tbody>
            @foreach ($recentHourly as $r)
            <tr><td style="text-align:left">{{ $r['date'] }}</td><td>{{ $r['sites'] }}</td><td class="c-muted">{{ $r['cnt'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

<!-- Delete data section -->
<div class="data-card" style="margin-top:8px">
    <div class="data-card-header" style="color:#ff7777"><i class="bi bi-trash3"></i> Danger Zone — Delete Data by Date</div>
    <div style="padding:16px;display:flex;gap:16px;flex-wrap:wrap">
        @if (showMeta())
        <form method="post" action="{{ url('/delete_data') }}" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="type" value="meta">
            <label class="form-label mb-0">Meta date:</label>
            <input type="date" name="date" class="form-control" style="width:160px">
            <button type="submit" class="btn-danger-custom" data-confirm="Delete all Meta data for this date?">
                <i class="bi bi-trash3"></i> Delete Meta
            </button>
        </form>
        @endif
        <form method="post" action="{{ url('/delete_data') }}" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="type" value="gam">
            <label class="form-label mb-0">GAM date:</label>
            <input type="date" name="date" class="form-control" style="width:160px">
            <button type="submit" class="btn-danger-custom" data-confirm="Delete all GAM data for this date?">
                <i class="bi bi-trash3"></i> Delete GAM
            </button>
        </form>
        <form method="post" action="{{ url('/delete_data') }}" style="display:flex;gap:8px;align-items:center">
            <input type="hidden" name="type" value="hourly">
            <label class="form-label mb-0">Hourly date:</label>
            <input type="date" name="date" class="form-control" style="width:160px">
            <button type="submit" class="btn-danger-custom" data-confirm="Delete all hourly GAM data for this date?">
                <i class="bi bi-trash3"></i> Delete Hourly
            </button>
        </form>
    </div>
</div>

@endsection
