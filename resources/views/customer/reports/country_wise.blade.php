@extends('customer.layout')

@section('content')

<div class="dash-header">
    <div>
        <div class="dash-title">COUNTRY WISE REPORT</div>
        <div class="dash-subtitle">Ad Exchange performance broken down by country</div>
    </div>
    <div class="date-picker-wrap" style="gap:8px;flex-wrap:wrap">
        <label><i class="bi bi-globe2"></i> Site</label>
        <select id="sitePicker" class="form-select" style="width:auto">
            <option value="">All sites</option>
            @foreach ($sites as $s)
            <option value="{{ $s }}" {{ $s === $site ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <label><i class="bi bi-calendar3"></i> Date</label>
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

<!-- KPI Cards -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-dollar"></i> Ad Exchange Revenue</div>
        <div class="kpi-value green">${{ number_format($tot['rev'], 2) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-cursor"></i> Ad Exchange CTR</div>
        <div class="kpi-value white">{{ $tot['ctr'] !== null ? number_format($tot['ctr'], 2) . '%' : '—' }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-graph-up"></i> Average eCPM</div>
        <div class="kpi-value gold">{{ $tot['ecpm'] !== null ? '$' . number_format($tot['ecpm'], 2) : '—' }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-arrow-repeat"></i> Total Requests</div>
        <div class="kpi-value white">{{ number_format($tot['req']) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-eye"></i> Impressions</div>
        <div class="kpi-value white">{{ number_format($tot['impr']) }}</div>
    </div>
</div>

<!-- Main Table -->
<div class="data-card">
    <div class="data-card-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span><i class="bi bi-flag"></i> Country Performance &mdash; {{ $displayDate }}</span>
        @if ($rows)
        <div style="margin-left:auto"><a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn-sm-custom"><i class="bi bi-download"></i> Export CSV</a></div>
        @endif
    </div>
    <div class="table-wrap">
        <table class="ledger" id="countryTable">
            <thead>
                <tr>
                    <th data-sort="str">Site <span class="sort-btn">⇅</span></th>
                    <th data-sort="str">Country <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">Ad Exchange CTR <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">Ad Exchange total requests <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">Ad Exchange revenue <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">Ad Exchange average eCPM <span class="sort-btn">⇅</span></th>
                    <th data-sort="num">Ad Exchange impressions <span class="sort-btn">⇅</span></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr>
                    <td data-v="{{ $r['site'] }}" style="color:var(--text-muted)">{{ $r['site'] }}</td>
                    <td data-v="{{ $r['dim'] }}">{{ $r['dim'] }}</td>
                    <td data-v="{{ $r['ctr'] }}">{{ rtrim(rtrim(number_format($r['ctr'], 2), '0'), '.') }}%</td>
                    <td data-v="{{ $r['req'] }}">{{ number_format($r['req']) }}</td>
                    <td class="c-green" data-v="{{ $r['rev'] }}">${{ number_format($r['rev'], 2) }}</td>
                    <td data-v="{{ $r['ecpm'] }}">${{ number_format($r['ecpm'], 2) }}</td>
                    <td data-v="{{ $r['impr'] }}">{{ number_format($r['impr']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:30px">
                    {{ $sites ? 'No country data for this date.' : 'No sites are assigned to your account yet.' }}
                </td></tr>
            @endforelse
            </tbody>
            @if ($rows)
            <tfoot>
                <tr>
                    <td style="text-align:left">TOTALS</td>
                    <td></td>
                    <td>{{ $tot['ctr'] !== null ? number_format($tot['ctr'], 2) . '%' : '—' }}</td>
                    <td>{{ number_format($tot['req']) }}</td>
                    <td class="c-green">${{ number_format($tot['rev'], 2) }}</td>
                    <td>{{ $tot['ecpm'] !== null ? '$' . number_format($tot['ecpm'], 2) : '—' }}</td>
                    <td>{{ number_format($tot['impr']) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

@push('scripts')
<script>
(function () {
    // Site / date dropdowns → reload with that filter.
    [['datePicker', 'date'], ['sitePicker', 'site']].forEach(function ([id, param]) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', function () {
            const url = new URL(window.location.href);
            if (this.value) url.searchParams.set(param, this.value); else url.searchParams.delete(param);
            window.location.href = url.toString();
        });
    });

    // Click a header to sort (click again to reverse).
    const table = document.getElementById('countryTable');
    const tbody = table.querySelector('tbody');
    table.querySelectorAll('th[data-sort]').forEach(function (th, col) {
        th.style.cursor = 'pointer';
        let asc = true;
        th.addEventListener('click', function () {
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(tr => tr.children.length > 1);
            const num  = th.dataset.sort === 'num';
            rows.sort(function (a, b) {
                const va = a.children[col].dataset.v ?? '', vb = b.children[col].dataset.v ?? '';
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
