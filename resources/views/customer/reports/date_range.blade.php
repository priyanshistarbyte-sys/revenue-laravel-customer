@extends('customer.layout')

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css">
@endpush

@section('content')

@php
    $dl = fn ($d) => (new \DateTime($d))->format('d M Y');
    // 10.00 → "10%", 12.50 → "12.5%"
    $pctLabel = fn($p) => rtrim(rtrim(number_format((float) $p, 2), '0'), '.') . '%';
    $totShare = $totGAM * $sharePct / 100;
    $totRest  = $totGAM - $totShare;
@endphp

<div class="dash-header">
    <div>
        <div class="dash-title">DATE RANGE REPORT</div>
        <div class="dash-subtitle">GAM revenue, CTR and share for each of your sites, day by day</div>
    </div>
    <div class="date-picker-wrap" style="gap:12px;flex-wrap:wrap">
        <span class="rate-badge" title="Approximate exchange rate used to convert GAM revenue">
            <i class="bi bi-currency-exchange"></i>
             USD/{{ getDefaultCurrency()['code'] }} (Approx)
            <b>{{ getDefaultCurrency()['symbol'] }}{{ number_format($usdRate, 2) }}</b>
        </span>
        <form method="get" action="{{ url('/reports/date-range') }}" style="display:flex;align-items:center;gap:8px;margin:0">
            <label><i class="bi bi-calendar3"></i> Select date range →</label>
            <div class="date-filter is-range">
                <i class="bi bi-calendar-week date-filter-icon"></i>
                <input type="text" class="form-control js-daterange" data-from="#drFrom" data-to="#drTo"
                       value="{{ $dl($from) }} - {{ $dl($to) }}" inputmode="none" autocomplete="off" title="Select date range">
            </div>
            <input type="hidden" name="from" id="drFrom" value="{{ $from }}">
            <input type="hidden" name="to" id="drTo" value="{{ $to }}">
        </form>
    </div>
</div>

@if ($capped)
<div class="alert alert-warning" style="margin-bottom:16px">
    <i class="bi bi-exclamation-triangle"></i> The range is limited to {{ $maxDays }} days — showing {{ $dl($from) }} to {{ $dl($to) }}.
</div>
@endif

<!-- KPI Cards -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-dollar"></i> GAM Revenue ($)</div>
        <div class="kpi-value green">${{ number_format($totGAMusd, 2) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-rupee"></i> GAM Revenue (₹)</div>
        <div class="kpi-value green">{{ fmtINR($totGAM) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-pie-chart"></i> Share ({{ $pctLabel($sharePct) }})</div>
        <div class="kpi-value gold">{{ fmtINR($totShare) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-pie-chart-fill"></i> Remaining ({{ $pctLabel($restPct) }})</div>
        <div class="kpi-value white">{{ fmtINR($totRest) }}</div>
    </div>
    <div class="kpi-card" title="{{ number_format($totClicks) }} clicks / {{ number_format($totImpr) }} impressions">
        <div class="kpi-label"><i class="bi bi-cursor"></i> GAM CTR</div>
        <div class="kpi-value white">{{ $totCtr !== null ? number_format($totCtr, 2) . '%' : '—' }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-globe2"></i> Sites</div>
        <div class="kpi-value white">{{ $siteCount }}</div>
    </div>
</div>

<!-- Main Table -->
<div class="data-card">
    <div class="data-card-header" style="flex-wrap:wrap">
        <i class="bi bi-table"></i> Site Performance &mdash; {{ $dl($from) }} to {{ $dl($to) }}
        <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
            @if ($rows)
            <a href="{{ request()->fullUrlWithQuery(['export' => 'csv', 'from' => $from, 'to' => $to]) }}" class="btn-sm-custom"><i class="bi bi-download"></i> Export CSV</a>
            @endif
        </div>
    </div>
    <div class="table-wrap">
        <table class="ledger" id="rangeTable">
            <thead>
                <tr>
                    <th data-sort="str">Date <span class="sort-btn">⇅</span></th>
                    <th data-sort="str">GAM URL <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">GAM Rev ($) <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">GAM Rev (₹) <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">CTR <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" title="Your share of GAM revenue (₹)">{{ $pctLabel($sharePct) }} <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" title="Remaining GAM revenue (₹)">{{ $pctLabel($restPct) }} <span class="sort-btn">⇅</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr class="{{ $r['hasData'] ? '' : 'row-nodata' }}">
                    <td data-v="{{ $r['date'] }}" style="text-align:left;white-space:nowrap">{{ (new \DateTime($r['date']))->format('d M Y') }}</td>
                    <td data-v="{{ implode(',', $r['gamSites']) }}">
                        @foreach ($r['gamSites'] as $gs)
                        <span style="color:var(--text-muted);display:inline-block">{{ $gs }}</span>@if (count($r['gamSites']) > 1)<br>@endif
                        @endforeach
                        @if (empty($r['gamSites']))<span style="color:var(--text-muted)">—</span>@endif
                    </td>
                    @if (!$r['hasData'])
                    <td class="c-muted" data-v="0">$0.00</td>
                    <td class="c-muted" data-v="0">₹0.00</td>
                    <td class="c-muted" data-v="">—</td>
                    <td class="c-muted" data-v="0">₹0.00</td>
                    <td class="c-muted" data-v="0">₹0.00</td>
                    @else
                    @php
                        $shareAmt = $r['gamINR'] * $sharePct / 100;
                        $restAmt  = $r['gamINR'] - $shareAmt;
                    @endphp
                    <td class="c-green" style="font-size:11px" data-v="{{ $r['gamUSD'] ?? 0 }}">${{ number_format($r['gamUSD'] ?? 0, 2) }}</td>
                    <td class="c-green" data-v="{{ $r['gamINR'] }}">{{ fmtINR($r['gamINR']) }}</td>
                    <td data-v="{{ $r['ctr'] ?? '' }}" title="{{ number_format($r['gamClicks']) }} clicks / {{ number_format($r['gamImpr']) }} impressions">{{ $r['ctr'] !== null ? number_format($r['ctr'], 2) . '%' : '—' }}</td>
                    <td class="c-gold" data-v="{{ $shareAmt }}">{{ fmtINR($shareAmt) }}</td>
                    <td data-v="{{ $restAmt }}">{{ fmtINR($restAmt) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:30px">No sites are assigned to your account yet.</td></tr>
            @endforelse
            </tbody>
            @if ($rows)
            <tfoot>
                <tr>
                    <td style="text-align:left" colspan="2">TOTALS</td>
                    <td class="c-green" style="font-size:11px">${{ number_format($totGAMusd, 2) }}</td>
                    <td class="c-green">{{ fmtINR($totGAM) }}</td>
                    <td>{{ $totCtr !== null ? number_format($totCtr, 2) . '%' : '—' }}</td>
                    <td class="c-gold">{{ fmtINR($totShare) }}</td>
                    <td>{{ fmtINR($totRest) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

@push('scripts')
{{-- Same date range picker as the admin Date Range report (initialised in app.js). --}}
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.30.1/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.min.js"></script>
<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}"></script>
<script>
(function () {
    // Click a header to sort (click again to reverse). Blank values sort last.
    const table = document.getElementById('rangeTable');
    const tbody = table.querySelector('tbody');
    table.querySelectorAll('th[data-sort]').forEach(function (th, col) {
        th.style.cursor = 'pointer';
        let asc = true;
        th.addEventListener('click', function () {
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.children.length > 1);
            const num  = th.dataset.sort === 'num';
            rows.sort(function (a, b) {
                const va = a.children[col].dataset.v ?? '', vb = b.children[col].dataset.v ?? '';
                if (va === '' && vb !== '') return 1;
                if (vb === '' && va !== '') return -1;
                const cmp = num ? (parseFloat(va) - parseFloat(vb)) : va.localeCompare(vb);
                return asc ? cmp : -cmp;
            });
            asc = !asc;
            rows.forEach(tr => tbody.appendChild(tr));
        });
    });
})();
</script>
@endpush

@endsection
