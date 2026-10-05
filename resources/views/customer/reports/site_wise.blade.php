@extends('customer.layout')

@section('content')

<div class="dash-header">
    <div>
        <div class="dash-title">SITE WISE REPORT</div>
        <div class="dash-subtitle">GAM revenue, CTR and share for each of your sites</div>
    </div>
    <div class="date-picker-wrap">
        <label><i class="bi bi-calendar3"></i> Select date →</label>
        <select id="datePicker" class="form-select" style="width:auto">
            @php
                $allDates = $datesRaw;
                if (!in_array($date, $allDates)) array_unshift($allDates, $date);
            @endphp
            @foreach ($allDates as $d)
            <option value="{{ $d }}" {{ $d === $date ? 'selected' : '' }}>{{ (new DateTime($d))->format('d M Y (D)') }}</option>
            @endforeach
        </select>
    </div>
</div>

@php
    // 10.00 → "10%", 12.50 → "12.5%"
    $pctLabel = fn($p) => rtrim(rtrim(number_format((float) $p, 2), '0'), '.') . '%';
@endphp

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
        <div class="kpi-label"><i class="bi bi-pie-chart"></i> Share</div>
        <div class="kpi-value gold">{{ $pctLabel($sharePct) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-pie-chart-fill"></i> Remaining</div>
        <div class="kpi-value white">{{ $pctLabel($restPct) }}</div>
    </div>
    <div class="kpi-card" title="{{ number_format($totClicks) }} clicks / {{ number_format($totImpr) }} impressions">
        <div class="kpi-label"><i class="bi bi-cursor"></i> GAM CTR</div>
        <div class="kpi-value white">{{ $totCtr !== null ? number_format($totCtr, 2) . '%' : '—' }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-globe2"></i> Sites</div>
        <div class="kpi-value white">{{ count($rows) }}</div>
    </div>
</div>

<!-- Main Table -->
<div class="data-card">
    <div class="data-card-header" style="flex-wrap:wrap">
        <i class="bi bi-table"></i> Site Performance &mdash; {{ $displayDate }}
        <span style="margin-left:auto;font-weight:400;font-size:11px;color:var(--text-muted)">USD/{{ getDefaultCurrency()['code'] }}: {{ number_format($usdRate, 2) }}</span>
    </div>
    <div class="table-wrap">
        <table class="ledger" id="siteTable">
            <thead>
                <tr>
                    <th data-sort="str">GAM URL <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">GAM Rev ($) <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">GAM Rev (₹) <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">CTR <span class="sort-btn">⇅</span></th>
                    <th>Share %</th>
                    <th>Remaining %</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr class="{{ $r['hasData'] ? '' : 'row-nodata' }}">
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
                    @else
                    <td class="c-green" style="font-size:11px" data-v="{{ $r['gamUSD'] ?? 0 }}">${{ number_format($r['gamUSD'] ?? 0, 2) }}</td>
                    <td class="c-green" data-v="{{ $r['gamINR'] }}">{{ fmtINR($r['gamINR']) }}</td>
                    <td data-v="{{ $r['ctr'] ?? '' }}" title="{{ number_format($r['gamClicks']) }} clicks / {{ number_format($r['gamImpr']) }} impressions">{{ $r['ctr'] !== null ? number_format($r['ctr'], 2) . '%' : '—' }}</td>
                    @endif
                    <td class="c-gold">{{ $pctLabel($sharePct) }}</td>
                    <td>{{ $pctLabel($restPct) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:30px">No sites are assigned to your account yet.</td></tr>
            @endforelse
            </tbody>
            @if ($rows)
            <tfoot>
                <tr>
                    <td style="text-align:left">TOTALS</td>
                    <td class="c-green" style="font-size:11px">${{ number_format($totGAMusd, 2) }}</td>
                    <td class="c-green">{{ fmtINR($totGAM) }}</td>
                    <td>{{ $totCtr !== null ? number_format($totCtr, 2) . '%' : '—' }}</td>
                    <td class="c-gold">{{ $pctLabel($sharePct) }}</td>
                    <td>{{ $pctLabel($restPct) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Date dropdown → reload for that day.
    const picker = document.getElementById('datePicker');
    if (picker) picker.addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('date', this.value);
        window.location.href = url.toString();
    });

    // Click a header to sort (click again to reverse). Blank values sort last.
    const table = document.getElementById('siteTable');
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
