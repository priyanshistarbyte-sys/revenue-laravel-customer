@extends('layouts.app')

@section('content')

@php
    $dl = fn ($d) => (new \DateTime($d))->format('d M Y');
    // Metric columns — each renders one cell per date.
    $metricCols = [
        ['label' => 'GAM Rev ($)',     'key' => 'gamUsd', 'render' => fn ($m) => '$' . number_format($m['gamUsd'], 2), 'cls' => fn ($m) => 'c-green'],
        ['label' => 'GAM Revenue (₹)', 'key' => 'gamInr', 'render' => fn ($m) => fmtINR($m['gamInr']),                 'cls' => fn ($m) => 'c-green'],
        ['label' => 'Meta Spend',      'key' => 'meta',   'render' => fn ($m) => fmtINR($m['meta']),                   'cls' => fn ($m) => 'c-orange'],
        ['label' => 'GST',             'key' => 'gst',    'render' => fn ($m) => fmtINR($m['gst']),                    'cls' => fn ($m) => 'c-gold'],
        ['label' => 'Total Cost',      'key' => 'cost',   'render' => fn ($m) => fmtINR($m['cost']),                   'cls' => fn ($m) => ''],
        ['label' => 'Net P/L',         'key' => 'pl',     'render' => fn ($m) => fmtINR($m['pl']),                     'cls' => fn ($m) => $m['pl'] >= 0 ? 'c-green' : 'c-red'],
        ['label' => 'Margin',          'key' => 'mgn',    'render' => fn ($m) => fmtPct($m['mgn']),                    'cls' => fn ($m) => $m['mgn'] >= 0 ? 'c-green' : 'c-red'],
    ];

@endphp

<div class="month-header">
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap">
        <h2><i class="bi bi-calendar-range"></i> DATE-WISE REPORT — {{ $dl($date1) }} vs {{ $dl($date2) }}</h2>
    </div>

    <form method="get" action="{{ url('/date-wise') }}"
          style="display:flex;align-items:center;justify-content:center;gap:12px;margin-top:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:6px">
            <span style="color:var(--text-muted);font-size:12px">Date 1</span>
            <div class="date-filter">
                <i class="bi bi-calendar3 date-filter-icon"></i>
                <input type="text" class="form-control js-datepicker" data-target="#dwDate1" value="{{ $dl($date1) }}" inputmode="none" autocomplete="off">
            </div>
            <input type="hidden" name="date1" id="dwDate1" value="{{ $date1 }}">
        </div>
        <div style="display:flex;align-items:center;gap:6px">
            <span style="color:var(--text-muted);font-size:12px">Date 2</span>
            <div class="date-filter">
                <i class="bi bi-calendar3 date-filter-icon"></i>
                <input type="text" class="form-control js-datepicker" data-target="#dwDate2" value="{{ $dl($date2) }}" inputmode="none" autocomplete="off">
            </div>
            <input type="hidden" name="date2" id="dwDate2" value="{{ $date2 }}">
        </div>
        @if ($viewUserId > 0)<input type="hidden" name="user" value="{{ $viewUserId }}">@endif
        @if ($adxFilter > 0)<input type="hidden" name="adx" value="{{ $adxFilter }}">@endif
        @if ($activeOnly)<input type="hidden" name="active" value="1">@endif
        <button type="submit" class="btn-primary-custom"><i class="bi bi-funnel-fill"></i> Apply</button>
    </form>

    <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-top:10px;flex-wrap:wrap">
        @if (canSeeAllUsers())
        <div style="display:flex;align-items:center;gap:8px">
            <span style="color:var(--text-muted);font-size:12px"><i class="bi bi-person"></i> User:</span>
            <select class="form-select js-user-filter" style="width:auto">
                <option value="">All users</option>
                @foreach (allUsers() as $uOpt)
                <option value="{{ (int)$uOpt['id'] }}" {{ $viewUserId === (int)$uOpt['id'] ? 'selected' : '' }}>
                    {{ $uOpt['name'] }}{{ $uOpt['is_admin'] ? ' (admin)' : '' }}
                </option>
                @endforeach
            </select>
        </div>
        @endif
        @if (!empty($adxOptions))
        <div style="display:flex;align-items:center;gap:8px">
            <span style="color:var(--text-muted);font-size:12px"><i class="bi bi-diagram-3"></i> ADX:</span>
            <select class="form-select js-adx-filter" style="width:auto">
                <option value="">All ADX</option>
                @foreach ($adxOptions as $ax)
                <option value="{{ (int)$ax['id'] }}" {{ $adxFilter === (int)$ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text-muted);font-size:12px">
            <input type="checkbox" class="js-active-filter" value="1" {{ $activeOnly ? 'checked' : '' }}
                   style="accent-color:var(--purple);width:15px;height:15px">
            Active only
        </label>
    </div>
</div>

@if (!$dbOk)
<div class="alert-custom alert-error"><i class="bi bi-exclamation-circle"></i> {{ $error }}</div>
@endif

@php
    $csvQs = ['date1' => $date1, 'date2' => $date2];
    if ($viewUserId > 0) $csvQs['user'] = $viewUserId;
    if ($adxFilter > 0)  $csvQs['adx']  = $adxFilter;
    if ($activeOnly)     $csvQs['active'] = '1';
    $csvUrl     = url('/date-wise') . '?' . http_build_query($csvQs + ['export' => 'csv']);
    $csvLastUrl = url('/date-wise') . '?' . http_build_query($csvQs + ['export' => 'last']);
@endphp
<div class="data-card">
    <div class="data-card-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span><i class="bi bi-table"></i> Link Performance — {{ $dl($date1) }} vs {{ $dl($date2) }}</span>
        <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
            <a href="{{ $csvUrl }}" class="btn-sm-custom"><i class="bi bi-download"></i> Export CSV</a>
            <a href="{{ $csvLastUrl }}" class="btn-sm-custom"><i class="bi bi-globe2"></i> CSV (last GAM URL)</a>
            <span style="font-size:11px;color:var(--text-muted);font-weight:400">USD/{{ getDefaultCurrency()['code'] }}: {{ number_format($usdRate, 2) }}</span>
        </div>
    </div>
    <div class="table-wrap">
        <table class="ledger" style="min-width:1600px">
            <thead>
                <tr>
                    <th rowspan="2" style="text-align:left">META URL <small style="color:var(--text-muted)">(spend)</small></th>
                    <th rowspan="2" style="text-align:left">GAM URL <small style="color:var(--text-muted)">(revenue)</small></th>
                    <th rowspan="2" style="text-align:left">ADX</th>
                    <th rowspan="2" class="js-sort" data-col="3" style="text-align:left;cursor:pointer;user-select:none" title="Sort by entry date">ENTRY DATE <span class="js-arrow" style="opacity:.4">↕</span></th>
                    @foreach ($metricCols as $mc)
                    <th colspan="2" style="border-left:1px solid #2e2e5a">{{ $mc['label'] }}</th>
                    @endforeach
                    <th rowspan="2" style="border-left:1px solid #2e2e5a">STATUS</th>
                </tr>
                <tr>
                    @foreach ($metricCols as $i => $mc)
                    <th class="js-sort" data-col="{{ 4 + $i * 2 }}" style="font-size:10px;color:#c4b5fd;border-left:1px solid #2e2e5a;cursor:pointer;user-select:none" title="Sort by {{ $mc['label'] }} — {{ $dl($date1) }}">{{ $dl($date1) }} <span class="js-arrow" style="opacity:.4">↕</span></th>
                    <th class="js-sort" data-col="{{ 4 + $i * 2 + 1 }}" style="font-size:10px;color:#7dd3fc;cursor:pointer;user-select:none" title="Sort by {{ $mc['label'] }} — {{ $dl($date2) }}">{{ $dl($date2) }} <span class="js-arrow" style="opacity:.4">↕</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    $gamHosts   = array_filter(array_map('trim', explode(',', $r['gam_url'])));
                @endphp
            <tr class="{{ $r['has'] ? '' : 'row-nodata' }}">
                <td style="text-align:left;font-weight:600;color:#c4b5fd">
                    <a class="url-tag" href="{{ $r['meta_url'] }}" target="_blank">{{ $r['meta_url'] }}</a>
                </td>
                <td style="text-align:left;font-size:11px;color:var(--text-muted)">
                    @foreach ($gamHosts as $gh)<div>{{ $gh }}</div>@endforeach
                </td>
                <td style="text-align:left">
                    @if ($r['adx_name'])<span style="background:#1a1a42;border-radius:10px;padding:1px 8px;font-size:11px;color:#7dd3fc"><i class="bi bi-diagram-3" style="font-size:9px"></i> {{ $r['adx_name'] }}</span>@else—@endif
                </td>
                <td data-sort="{{ $r['entry'] !== '' ? strtotime($r['entry']) : '' }}"
                    style="text-align:left;font-size:11px;color:var(--text-muted);white-space:nowrap">{{ $r['entry'] !== '' ? date('Y-m-d', strtotime($r['entry'])) : '—' }}</td>
                @foreach ($metricCols as $mc)
                    <td class="{{ $mc['cls']($r['d1']) }}" data-sort="{{ $r['d1'][$mc['key']] }}" style="border-left:1px solid #2e2e5a">{{ $mc['render']($r['d1']) }}</td>
                    <td class="{{ $mc['cls']($r['d2']) }}" data-sort="{{ $r['d2'][$mc['key']] }}">{{ $mc['render']($r['d2']) }}</td>
                @endforeach
                <td style="border-left:1px solid #2e2e5a">
                    @if (!$r['active'])
                        <span style="background:#2e1a1a;color:#f87171;border-radius:10px;font-size:9px;padding:1px 6px">Inactive</span>
                    @elseif (!$r['has'])<span class="badge-nodata">No data</span>
                    @endif
                </td>
            </tr>
            @empty
                <tr><td colspan="{{ 4 + count($metricCols) * 2 + 1 }}" style="text-align:center;color:var(--text-muted);padding:30px">No links found.</td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:left">TOTALS</td>
                    @foreach ($metricCols as $mc)
                    <td class="{{ $mc['cls']($t1) }}" style="border-left:1px solid #2e2e5a">{{ $mc['render']($t1) }}</td>
                    <td class="{{ $mc['cls']($t2) }}">{{ $mc['render']($t2) }}</td>
                    @endforeach
                    <td style="border-left:1px solid #2e2e5a"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
// Click a per-date column header to sort the table rows by that column's numeric
// value. Clicking the same header again flips the direction. TOTALS (tfoot) stays put.
document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('.ledger');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    table.querySelectorAll('th.js-sort').forEach(th => {
        th.addEventListener('click', () => {
            const col = parseInt(th.dataset.col, 10);
            const asc = th.dataset.dir !== 'asc'; // toggle; default first click = desc

            const rows = [...tbody.querySelectorAll('tr')].filter(r => !r.querySelector('td[colspan]'));
            rows.sort((a, b) => {
                const av = parseFloat(a.children[col]?.dataset.sort ?? 'NaN');
                const bv = parseFloat(b.children[col]?.dataset.sort ?? 'NaN');
                const x = isNaN(av) ? -Infinity : av;
                const y = isNaN(bv) ? -Infinity : bv;
                return asc ? x - y : y - x;
            });
            rows.forEach(r => tbody.appendChild(r));

            // Reset all arrows, then mark this column's direction.
            table.querySelectorAll('th.js-sort').forEach(o => {
                o.dataset.dir = '';
                const ar = o.querySelector('.js-arrow');
                if (ar) { ar.textContent = '↕'; ar.style.opacity = '.4'; }
            });
            th.dataset.dir = asc ? 'asc' : 'desc';
            const arrow = th.querySelector('.js-arrow');
            if (arrow) { arrow.textContent = asc ? '↑' : '↓'; arrow.style.opacity = '1'; }
        });
    });
});
</script>

@endsection
